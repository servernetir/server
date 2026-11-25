'use strict';

// Plain Node tests. No browser, microphone, WebSocket or SIP provider is used.
const test = require('node:test');
const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const { normalizeDialString, preferPcmu, mount } = require('../phone.js');
const OWNED_PC_CONFIG = { iceServers: [{ urls: 'stun:pbx.servernet.cloud:3478' }] };

class Element extends EventEmitter {
  constructor(id) {
    super();
    Object.assign(this, { id, dataset: {}, value: '', textContent: '', attrs: {},
      disabled: false, hidden: false, controls: false, srcObject: null });
  }
  addEventListener(name, listener) { this.on(name, listener); }
  setAttribute(name, value) { this.attrs[name] = value; }
  focus() { this.focused = true; }
  pause() {}
  play() { return Promise.resolve(); }
  async click() {
    for (const listener of this.listeners('click')) await listener({ target: this });
  }
}

class Session extends EventEmitter {
  constructor(direction) {
    super();
    Object.assign(this, { direction, established: false, tones: [], muted: false,
      remote_identity: { uri: { user: '02171057757' } } });
  }
  isEstablished() { return this.established; }
  terminate(options = {}) { this.terminated = options; this.emit('ended'); }
  answer(options) { this.options = options; this.established = true; this.emit('confirmed'); }
  mute() { this.muted = true; this.emit('muted'); }
  unmute() { this.muted = false; this.emit('unmuted'); }
  isMuted() { return { audio: this.muted }; }
  sendDTMF(value, options) { this.tones.push({ value, options }); }
}

function harness(t, options = {}) {
  const ids = ['extension', 'connect', 'disconnect', 'call', 'hangup', 'mute', 'answer',
    'reject', 'destination', 'status', 'call-status', 'remote-number', 'remote-audio'];
  const elements = Object.fromEntries(ids.map((id) => [id, new Element(id)]));
  elements.extension.value = '00000000-0000-4000-8000-000000000001';
  const tone = new Element('tone');
  tone.dataset.tone = '1';
  const root = new Element('voice-app');
  root.dataset = { lang: options.lang || 'en', tokenName: 'token1', tokenHash: 'hash1' };
  root.ownerDocument = new EventEmitter();
  Object.assign(root.ownerDocument, { documentElement: { lang: root.dataset.lang }, visibilityState: 'visible' });
  root.ownerDocument.addEventListener = root.ownerDocument.on.bind(root.ownerDocument);
  root.querySelector = (selector) => elements[selector.slice(1)];
  root.querySelectorAll = () => [tone];

  const h = { elements, root, tone, requests: [], uas: [], micCalls: 0, trackStops: 0,
    socketStops: 0, debugDisabled: false, timeouts: new Map(), intervals: new Map(), delays: new Map() };
  h.stream = () => ({ getTracks: () => [{ stop() { h.trackStops += 1; } }] });
  h.streamFactory = () => Promise.resolve(h.stream());
  h.responseFactory = () => ({ ok: true, status: 200, json: async () => ({
    ok: true, extension: '1001',
    csrf: { name: 'token' + (h.requests.length + 1), hash: 'hash' + (h.requests.length + 1) },
    sip: { uri: 'sip:1001@pbx.servernet.cloud', authorization_user: '1001',
      password: 'fake-password-used-only-in-this-test', display_name: 'Operator', domain: 'pbx.servernet.cloud' }
  }) });
  class UA extends EventEmitter {
    constructor(configuration) {
      super();
      this.configuration = { ...configuration };
      this.calls = [];
      this.connected = false;
      this.registrations = 0;
      h.uas.push(this);
    }
    start() { this.emit('connected'); this.emit('registered'); }
    emit(name, ...args) {
      if (name === 'connected') this.connected = true;
      if (name === 'disconnected') this.connected = false;
      return super.emit(name, ...args);
    }
    stop() { this.stopped = true; this.connected = false; }
    isConnected() { return this.connected; }
    register() { this.registrations += 1; }
    set(key, value) { this.configuration[key] = value; }
    call(uri, callOptions) {
      const session = new Session('outgoing');
      session.options = callOptions;
      this.calls.push({ uri, session });
      this.emit('newRTCSession', { session });
      return session;
    }
  }
  const win = new EventEmitter();
  let timerId = 0;
  Object.assign(win, {
    isSecureContext: true, RTCPeerConnection: function () {}, FormData, AbortController,
    setTimeout(callback, delay) { h.timeouts.set(++timerId, callback); h.delays.set(timerId, delay); return timerId; },
    clearTimeout(id) { h.timeouts.delete(id); },
    setInterval(callback) { h.intervals.set(++timerId, callback); return timerId; },
    clearInterval(id) { h.intervals.delete(id); },
    navigator: { mediaDevices: { getUserMedia(constraints) {
      h.micCalls += 1;
      assert.deepEqual(constraints, { audio: true, video: false });
      return h.streamFactory();
    } } },
    JsSIP: { UA, WebSocketInterface: class {
      constructor(url) { this.via_transport = 'WSS'; assert.equal(url, 'wss://pbx.servernet.cloud/sip/'); }
      disconnect() { h.socketStops += 1; }
    }, debug: { disable() { h.debugDisabled = true; } } }
  });
  win.addEventListener = win.on.bind(win);
  win.fetch = async (url, fetchOptions) => {
    h.requests.push({ url, options: fetchOptions, fields: Object.fromEntries(fetchOptions.body.entries()) });
    return h.responseFactory();
  };
  h.win = win;
  h.app = mount(root, win);
  h.connect = () => elements.connect.click();
  h.close = () => h.app.disconnect();
  h.call = async (number = '02171057757') => {
    elements.destination.value = number;
    await elements.call.click();
    return h.uas.at(-1).calls.at(-1)?.session;
  };
  h.incoming = () => {
    const session = new Session('incoming');
    h.uas.at(-1).emit('newRTCSession', { session });
    return session;
  };
  t.after(h.close);
  return h;
}

test('normalizes Persian, Arabic and formatted telephone digits', () => {
  assert.equal(normalizeDialString('۰۲۱ ۷۱۰۵-۷۷۵۷'), '02171057757');
  assert.equal(normalizeDialString('+٩٨ (٢١)٧١٠٥٧٧٥٧'), '+982171057757');
  assert.equal(normalizeDialString('1001'), '1001');
  assert.equal(normalizeDialString('*981001'), '*981001');
});

test('rejects SIP URI, header injection and unbounded destinations', () => {
  for (const value of ['sip:1001@evil.example', '1001\r\nX: value', '<script>',
    '+12#345', '12;transport=udp', '1'.repeat(25), '', '1']) {
    assert.equal(normalizeDialString(value), null);
  }
});

test('codec API selects PCMU and 8000-Hz telephone events only', () => {
  const codecs = [
    { mimeType: 'audio/opus', clockRate: 48000 }, { mimeType: 'audio/PCMU', clockRate: 8000 },
    { mimeType: 'audio/PCMA', clockRate: 8000 }, { mimeType: 'audio/telephone-event', clockRate: 8000 },
    { mimeType: 'audio/telephone-event', clockRate: 48000 }
  ];
  let preferences;
  preferPcmu({ getTransceivers: () => [{ sender: { track: { kind: 'audio' } },
    setCodecPreferences(value) { preferences = value; } }] },
  { RTCRtpSender: { getCapabilities: () => ({ codecs }) } });
  assert.deepEqual(preferences, [codecs[1], codecs[3]]);
});

test('unsupported codec API falls back without changing browser SDP', () => {
  assert.doesNotThrow(() => preferPcmu({}, {}));
  assert.doesNotThrow(() => preferPcmu({ getTransceivers: () => [{
    sender: { track: { kind: 'audio' } }, setCodecPreferences() { throw new Error('Unsupported'); }
  }] }, { RTCRtpSender: { getCapabilities: () => ({ codecs: [{ mimeType: 'audio/PCMU', clockRate: 8000 }] }) } }));
  let changed = false;
  preferPcmu({ getTransceivers() { changed = true; return []; } },
    { RTCRtpSender: { getCapabilities: () => ({ codecs: [{ mimeType: 'audio/opus', clockRate: 48000 }] }) } });
  assert.equal(changed, false);
});

test('mount is idle; only Connect fetches credentials and rotates native CSRF', async (t) => {
  const h = harness(t);
  assert.equal(h.requests.length, 0);
  assert.equal(h.micCalls, 0);
  assert.equal(h.elements.call.disabled, true);
  await h.connect();
  const request = h.requests[0];
  assert.equal(request.url, 'api.php');
  assert.deepEqual(request.fields, { action: 'connect', extension_uuid: h.elements.extension.value, token1: 'hash1' });
  assert.equal(request.options.credentials, 'same-origin');
  assert.equal(request.options.cache, 'no-store');
  assert.equal(request.options.redirect, 'error');
  assert.equal(h.root.dataset.tokenName, 'token2');
  assert.equal(h.root.dataset.tokenHash, 'hash2');
  assert.equal(h.debugDisabled, true);
  assert.equal(h.uas[0].configuration.sockets[0].via_transport, 'WS');
  assert.equal(h.micCalls, 0);
  assert.equal(h.elements.call.disabled, false);
  assert.equal(h.uas[0].configuration.password, 'fake-password-used-only-in-this-test');
  assert.doesNotMatch(Object.values(h.elements).map((element) => element.textContent).join(' '), /fake-password/);
});

test('insecure or unsupported browser does not fetch credentials', async (t) => {
  const h = harness(t);
  h.win.isSecureContext = false;
  await h.connect();
  assert.equal(h.requests.length, 0);
  assert.equal(h.micCalls, 0);
  assert.equal(h.uas.length, 0);
});

test('HTTP permission failure stops connection and uses a generic translated error', async (t) => {
  const h = harness(t);
  h.responseFactory = () => ({ ok: false, status: 403 });
  await h.connect();
  assert.equal(h.uas.length, 0);
  assert.equal(h.micCalls, 0);
  assert.equal(h.elements.status.textContent, 'Your session or page permission expired. Refresh this page.');
  assert.equal(h.elements.connect.disabled, false);
});

test('outgoing call uses audio only, one session, DTMF and mute without an SDP rewrite listener', async (t) => {
  const h = harness(t);
  await h.connect();
  const session = await h.call('۰۲۱۷۱۰۵۷۷۵۷');
  assert.equal(h.micCalls, 1);
  assert.equal(h.uas[0].calls[0].uri, 'sip:02171057757@pbx.servernet.cloud');
  assert.deepEqual(session.options.mediaConstraints, { audio: true, video: false });
  assert.deepEqual(session.options.pcConfig, OWNED_PC_CONFIG);
  assert.equal(session.listenerCount('sdp'), 0);
  session.established = true;
  session.emit('confirmed');
  await h.tone.click();
  assert.equal(session.tones[0].value, '1');
  assert.equal(session.tones[0].options.transportType, 'RFC2833');
  await h.elements.mute.click();
  assert.equal(session.muted, true);
  await h.elements.mute.click();
  assert.equal(session.muted, false);
  const busy = h.incoming();
  assert.equal(busy.terminated.status_code, 486);
  assert.equal(h.micCalls, 1);
  await h.elements.hangup.click();
  assert.ok(h.trackStops > 0);
  assert.equal(h.elements['remote-audio'].srcObject, null);
  assert.equal(h.intervals.size, 1); // Connection refresh survives hanging up a call.
});

test('incoming ringing never requests microphone until explicit Answer', async (t) => {
  const h = harness(t);
  await h.connect();
  const incoming = h.incoming();
  assert.equal(h.micCalls, 0);
  assert.equal(h.elements.answer.hidden, false);
  await h.elements.answer.click();
  assert.equal(h.micCalls, 1);
  assert.equal(incoming.established, true);
  assert.deepEqual(incoming.options.mediaConstraints, { audio: true, video: false });
  assert.deepEqual(incoming.options.pcConfig, OWNED_PC_CONFIG);
});

test('API-supplied ICE URLs cannot override the single owned STUN endpoint', async (t) => {
  const h = harness(t);
  const validResponse = h.responseFactory;
  h.responseFactory = () => {
    const response = validResponse();
    const validJson = response.json;
    response.json = async () => {
      const data = await validJson();
      data.sip.iceServers = [{ urls: 'stun:unexpected.invalid:3478' }];
      data.sip.pcConfig = { iceServers: [{ urls: 'turn:unexpected.invalid:3478' }] };
      data.iceServers = [{ urls: 'stun:another.invalid:3478' }];
      return data;
    };
    return response;
  };
  await h.connect();
  assert.equal(h.micCalls, 0);
  const outgoing = await h.call();
  assert.deepEqual(outgoing.options.pcConfig, OWNED_PC_CONFIG);
  await h.elements.hangup.click();
  const incoming = h.incoming();
  await h.elements.answer.click();
  assert.deepEqual(incoming.options.pcConfig, OWNED_PC_CONFIG);
});

test('488, media, ICE and browser media errors show only the limited translated message', async (t) => {
  const labels = [
    ['fa', 'ارتباط صوتی تماس برقرار نشد. اتصال اینترنت را بررسی و دوباره تلاش کنید.'],
    ['en', 'Audio could not connect. Check your network and try again.'],
    ['tr', 'Ses bağlantısı kurulamadı. Ağınızı kontrol edip yeniden deneyin.']
  ];
  const failures = [
    { message: { status_code: 488, reason_phrase: 'RAW_REMOTE_STATUS' } },
    { cause: 'Busy', message: { status_code: 488, reason_phrase: 'RAW_REMOTE_BUSY_STATUS' } },
    ...['Incompatible SDP', 'WebRTC Error', 'ICE Connection Error', 'media transport', 'RTP failure', 'codec failure']
      .map((cause) => ({ cause: cause + ' RAW_REMOTE_CAUSE' })),
    { error: { name: 'OperationError', message: 'RAW_BROWSER_ERROR' } },
    { error: { name: 'NotSupportedError', message: 'RAW_BROWSER_ERROR' } }
  ];
  for (const [lang, expected] of labels) {
    const h = harness(t, { lang });
    await h.connect();
    for (const failure of failures) {
      const session = await h.call();
      session.emit('failed', failure);
      assert.equal(h.elements['call-status'].textContent, expected, lang + ': ' + JSON.stringify(failure));
      assert.doesNotMatch(Object.values(h.elements).map((element) => element.textContent).join(' '), /RAW_|488|OperationError|NotSupportedError/);
      assert.equal(h.elements.call.disabled, false);
      assert.equal(h.elements.hangup.disabled, true);
      assert.equal(h.elements['remote-audio'].srcObject, null);
    }
    assert.equal(h.trackStops, failures.length);
    h.close();
  }
});

test('busy, rejection, unanswered and unknown failures retain limited user-facing labels', async (t) => {
  const h = harness(t);
  await h.connect();
  for (const [cause, expected] of [
    ['Busy', 'The destination is busy.'], ['Rejected', 'Call rejected.'],
    ['No Answer', 'No answer.'], ['Request Timeout', 'No answer.'],
    ['RAW_UNKNOWN_REMOTE_CAUSE', 'The call could not connect. Check the number and line status.']
  ]) {
    const session = await h.call();
    session.emit('failed', { cause, message: { reason_phrase: 'RAW_REMOTE_DETAIL' } });
    assert.equal(h.elements['call-status'].textContent, expected);
    assert.doesNotMatch(h.elements['call-status'].textContent, /RAW_/);
  }
});

test('direct outgoing browser media exception uses the limited media error and closes audio', async (t) => {
  const h = harness(t);
  await h.connect();
  h.uas[0].call = () => { throw Object.assign(new Error('RAW_OUTGOING_MEDIA_ERROR'), { name: 'OperationError' }); };
  await h.call();
  assert.equal(h.elements['call-status'].textContent, 'Audio could not connect. Check your network and try again.');
  assert.equal(h.trackStops, 1);
  assert.equal(h.elements.call.disabled, false);
  assert.doesNotMatch(h.elements['call-status'].textContent, /RAW_/);
});

test('direct incoming answer exception keeps its media error despite synchronous termination', async (t) => {
  const h = harness(t);
  await h.connect();
  const incoming = h.incoming();
  incoming.answer = () => { throw Object.assign(new Error('RAW_INCOMING_MEDIA_ERROR'), { name: 'NotSupportedError' }); };
  await h.elements.answer.click();
  assert.equal(h.elements['call-status'].textContent, 'Audio could not connect. Check your network and try again.');
  assert.ok(incoming.terminated);
  assert.equal(h.trackStops, 1);
  assert.equal(h.elements.call.disabled, false);
  assert.doesNotMatch(h.elements['call-status'].textContent, /RAW_/);
});

test('rejecting an incoming call requests no microphone', async (t) => {
  const h = harness(t);
  await h.connect();
  const incoming = h.incoming();
  await h.elements.reject.click();
  assert.equal(incoming.terminated.status_code, 486);
  assert.equal(h.micCalls, 0);
  assert.equal(h.elements.call.disabled, false);
});

test('Disconnect clears UA credentials and explicit reconnect uses the rotated token', async (t) => {
  const h = harness(t);
  await h.connect();
  const session = await h.call();
  await h.elements.disconnect.click();
  assert.ok(session.terminated);
  assert.equal(h.uas[0].stopped, true);
  assert.equal(h.uas[0].configuration.password, '');
  assert.equal(h.socketStops, 1);
  assert.ok(h.trackStops > 0);
  assert.equal(h.elements.call.disabled, true);
  await h.connect();
  assert.equal(h.requests[1].fields.token2, 'hash2');
  assert.equal(h.micCalls, 1);
});

test('transient transport loss preserves native recovery and resumes ringing without microphone access', async (t) => {
  const h = harness(t);
  await h.connect();
  const ua = h.uas[0];
  ua.emit('disconnected');
  ua.emit('unregistered');
  assert.equal(ua.stopped, undefined);
  assert.notEqual(ua.configuration.password, '');
  assert.equal(h.requests.length, 1);
  assert.equal(h.uas.length, 1);
  assert.equal(h.elements.connect.disabled, true);
  assert.equal(h.elements.disconnect.disabled, false);
  assert.equal(h.elements.call.disabled, true);
  assert.equal(h.elements.status.textContent, 'Connection lost; reconnecting…');
  assert.equal(h.timeouts.size, 0);
  assert.equal(h.intervals.size, 0);
  const unavailable = h.incoming();
  assert.equal(unavailable.terminated.status_code, 480);
  ua.emit('connected');
  ua.emit('registered');
  assert.equal(h.elements.call.disabled, false);
  assert.equal(h.elements.status.textContent, 'Ready to call');
  const incoming = h.incoming();
  assert.equal(incoming.terminated, undefined);
  assert.equal(h.elements.answer.hidden, false);
  assert.equal(h.micCalls, 0);
  assert.equal(h.requests.length, 1);
});

test('loss during a call closes audio and recovery never redials or reanswers', async (t) => {
  const h = harness(t);
  await h.connect();
  const session = await h.call();
  h.uas[0].emit('disconnected');
  assert.ok(session.terminated);
  assert.equal(h.trackStops, 1);
  assert.equal(h.elements['remote-audio'].srcObject, null);
  h.uas[0].emit('connected');
  h.uas[0].emit('registered');
  assert.equal(h.micCalls, 1);
  assert.equal(h.uas[0].calls.length, 1);
});

test('Disconnect cancels recovery timers and ignores late old-UA events', async (t) => {
  const h = harness(t);
  await h.connect();
  const old = h.uas[0];
  old.emit('registrationFailed', { response: { status_code: 503 } });
  const lateRetry = [...h.timeouts.values()][0];
  assert.equal(typeof lateRetry, 'function');
  await h.elements.disconnect.click();
  assert.equal(old.stopped, true);
  assert.equal(old.configuration.password, '');
  assert.equal(h.timeouts.size, 0);
  assert.equal(h.intervals.size, 0);
  await h.connect();
  lateRetry();
  old.emit('registered');
  old.emit('disconnected');
  assert.equal(old.registrations, 0);
  assert.equal(h.elements.call.disabled, false);
  assert.equal(h.requests.length, 2);
});

test('temporary registration failure retries once per backoff and refreshes after recovery', async (t) => {
  const h = harness(t);
  await h.connect();
  const ua = h.uas[0];
  for (const delay of [10000, 20000, 30000, 30000]) {
    ua.emit('registrationFailed', { response: { status_code: 503 }, cause: 'RAW_REMOTE_DETAIL' });
    ua.emit('unregistered');
    assert.equal(h.timeouts.size, 1);
    const [id, retry] = [...h.timeouts.entries()][0];
    assert.equal(h.delays.get(id), delay);
    h.timeouts.delete(id);
    retry();
  }
  assert.equal(ua.registrations, 4);
  assert.equal(h.micCalls, 0);
  ua.emit('registered');
  const refresh = [...h.intervals.values()][0];
  refresh();
  refresh(); // A successful JsSIP refresh does not emit registered again.
  assert.equal(ua.registrations, 6);
  h.win.emit('online');
  h.root.ownerDocument.emit('visibilitychange');
  assert.equal(ua.registrations, 8);
  assert.equal(h.requests.length, 1);
  h.close();
  refresh();
  h.win.emit('online');
  h.root.ownerDocument.emit('visibilitychange');
  assert.equal(ua.registrations, 8);
});

test('authentication and unknown registration failures stop and clear credentials', async (t) => {
  for (const event of [{ response: { status_code: 403 } }, { response: { status_code: 401 } },
    { response: { status_code: 404 } }, { cause: 'RAW_UNKNOWN_CAUSE' }]) {
    const h = harness(t);
    await h.connect();
    const ua = h.uas[0];
    ua.emit('registrationFailed', event);
    ua.emit('unregistered');
    assert.equal(ua.stopped, true);
    assert.equal(ua.configuration.password, '');
    assert.equal(h.timeouts.size, 0);
    assert.equal(h.intervals.size, 0);
    assert.equal(h.elements.connect.disabled, false);
    assert.doesNotMatch(h.elements.status.textContent, /RAW_/);
  }
});

test('pagehide and BFCache restore require another explicit Connect', async (t) => {
  const h = harness(t);
  await h.connect();
  const session = await h.call();
  h.win.emit('pagehide');
  h.win.emit('pageshow', { persisted: true });
  assert.ok(session.terminated);
  assert.equal(h.uas[0].stopped, true);
  assert.equal(h.requests.length, 1);
  assert.ok(h.trackStops > 0);
  assert.equal(h.elements.call.disabled, true);
});

test('pagehide while microphone permission is pending closes the eventual stream', async (t) => {
  const h = harness(t);
  await h.connect();
  let resolveMicrophone;
  h.streamFactory = () => new Promise((resolve) => { resolveMicrophone = resolve; });
  const pending = h.call();
  assert.equal(h.micCalls, 1);
  const busy = h.incoming();
  assert.equal(busy.terminated.status_code, 486);
  h.win.emit('pagehide');
  resolveMicrophone(h.stream());
  await pending;
  assert.equal(h.trackStops, 1);
  assert.equal(h.uas[0].calls.length, 0);
  assert.equal(h.uas[0].stopped, true);
});

test('late credential response after Disconnect cannot construct or start a UA', async (t) => {
  const h = harness(t);
  let resolveJson;
  const late = { ok: true, sip: { password: 'fake-late-password' } };
  h.responseFactory = () => ({ ok: true, status: 200,
    json: () => new Promise((resolve) => { resolveJson = resolve; }) });
  const pending = h.connect();
  await Promise.resolve();
  await h.elements.disconnect.click();
  assert.equal(h.requests[0].options.signal.aborted, true);
  resolveJson(late);
  await pending;
  assert.equal(h.uas.length, 0);
  assert.equal(late.sip.password, '');
  assert.equal(h.micCalls, 0);
});

test('microphone refusal recovers controls and leaves no call session', async (t) => {
  const h = harness(t);
  await h.connect();
  h.streamFactory = () => Promise.reject(new Error('Permission denied'));
  await h.call();
  assert.equal(h.micCalls, 1);
  assert.equal(h.uas[0].calls.length, 0);
  assert.equal(h.elements.call.disabled, false);
  assert.equal(h.elements.hangup.disabled, true);
  assert.match(h.elements['call-status'].textContent, /Microphone access was not granted/);
});

test('connection status has working Persian, English and Turkish translations', async (t) => {
  for (const [lang, expected] of [['fa', 'آماده تماس'], ['en', 'Ready to call'], ['tr', 'Aramaya hazır']]) {
    const h = harness(t, { lang });
    await h.connect();
    assert.equal(h.elements.status.textContent, expected);
    h.close();
  }
});
