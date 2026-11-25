/* ServerNet Voice — credentials stay in this tab's memory until Disconnect. */
(function (global) {
  'use strict';

  const WSS_URL = 'wss://pbx.servernet.cloud/sip/';
  const STUN_URL = 'stun:pbx.servernet.cloud:3478';
  function mediaConfiguration() {
    return { iceServers: [{ urls: STUN_URL }] };
  }
  const messages = {
    fa: {
      offline: 'قطع ارتباط', connecting: 'در حال اتصال…', registering: 'در حال ثبت داخلی…',
      ready: 'آماده تماس', ended: 'تماس پایان یافت', calling: 'در حال شماره‌گیری…',
      ringing: 'در حال زنگ خوردن…', incoming: 'تماس ورودی', talking: 'در حال مکالمه',
      microphone: 'در انتظار دسترسی میکروفن…', microphoneDenied: 'دسترسی میکروفن داده نشد. مجوز مرورگر را بررسی کنید.',
      unavailable: 'مرورگر شما تماس صوتی امن را پشتیبانی نمی‌کند.',
      unavailableLibrary: 'برنامه تماس بارگذاری نشد. صفحه را دوباره باز کنید.',
      connectFailed: 'اتصال برقرار نشد. دوباره روی اتصال بزنید.',
      expired: 'نشست یا مجوز صفحه منقضی شده است. صفحه را تازه کنید.',
      rateLimited: 'کمی صبر کنید و دوباره تلاش کنید.',
      disconnected: 'ارتباط قطع شد. برای اتصال دوباره دکمه اتصال را بزنید.',
      invalidNumber: 'شماره داخلی یا شماره تلفن معتبر وارد کنید.',
      callFailed: 'تماس برقرار نشد. شماره و وضعیت خط را بررسی کنید.',
      mediaFailed: 'ارتباط صوتی تماس برقرار نشد. اتصال اینترنت را بررسی و دوباره تلاش کنید.',
      busy: 'مقصد مشغول است.', rejected: 'تماس رد شد.', noAnswer: 'پاسخی دریافت نشد.',
      codec: 'کدک صوتی این تماس پشتیبانی نمی‌شود.',
      dtmfFailed: 'ارسال کلید انجام نشد.', mute: 'بی‌صدا کردن', unmute: 'فعال کردن میکروفن',
      audioBlocked: 'برای شنیدن صدای تماس، پخش‌کننده صدا را فعال کنید.',
      disconnectedPage: 'برای شروع دوباره، روی اتصال بزنید.',
      elapsed: 'مدت تماس', unknownCaller: 'شماره ناشناس'
    },
    en: {
      offline: 'Disconnected', connecting: 'Connecting…', registering: 'Registering extension…',
      ready: 'Ready to call', ended: 'Call ended', calling: 'Calling…', ringing: 'Ringing…',
      incoming: 'Incoming call', talking: 'In call', microphone: 'Waiting for microphone access…',
      microphoneDenied: 'Microphone access was not granted. Check your browser permissions.',
      unavailable: 'Your browser does not support secure voice calls.',
      unavailableLibrary: 'The phone could not load. Reopen this page.',
      connectFailed: 'Could not connect. Select Connect to try again.',
      expired: 'Your session or page permission expired. Refresh this page.',
      rateLimited: 'Please wait briefly before trying again.',
      disconnected: 'Connection lost. Select Connect to reconnect.',
      invalidNumber: 'Enter a valid extension or telephone number.',
      callFailed: 'The call could not connect. Check the number and line status.',
      mediaFailed: 'Audio could not connect. Check your network and try again.',
      busy: 'The destination is busy.', rejected: 'Call rejected.', noAnswer: 'No answer.',
      codec: 'This call does not support the required audio codec.',
      dtmfFailed: 'Could not send the keypad tone.', mute: 'Mute', unmute: 'Unmute',
      audioBlocked: 'Use the audio player to hear the call.',
      disconnectedPage: 'Select Connect to start again.', elapsed: 'Call duration', unknownCaller: 'Unknown caller'
    },
    tr: {
      offline: 'Bağlı değil', connecting: 'Bağlanıyor…', registering: 'Dahili kaydediliyor…',
      ready: 'Aramaya hazır', ended: 'Arama sona erdi', calling: 'Aranıyor…', ringing: 'Çalıyor…',
      incoming: 'Gelen arama', talking: 'Görüşmede', microphone: 'Mikrofon izni bekleniyor…',
      microphoneDenied: 'Mikrofon izni verilmedi. Tarayıcı izinlerini kontrol edin.',
      unavailable: 'Tarayıcınız güvenli sesli aramayı desteklemiyor.',
      unavailableLibrary: 'Telefon yüklenemedi. Bu sayfayı yeniden açın.',
      connectFailed: 'Bağlantı kurulamadı. Yeniden denemek için Bağlan düğmesine basın.',
      expired: 'Oturumunuz veya sayfa izniniz sona erdi. Sayfayı yenileyin.',
      rateLimited: 'Yeniden denemeden önce biraz bekleyin.',
      disconnected: 'Bağlantı kesildi. Yeniden bağlanmak için Bağlan düğmesine basın.',
      invalidNumber: 'Geçerli bir dahili veya telefon numarası girin.',
      callFailed: 'Arama bağlanamadı. Numarayı ve hat durumunu kontrol edin.',
      mediaFailed: 'Ses bağlantısı kurulamadı. Ağınızı kontrol edip yeniden deneyin.',
      busy: 'Aranan numara meşgul.', rejected: 'Arama reddedildi.', noAnswer: 'Yanıt yok.',
      codec: 'Bu arama gerekli ses kodeğini desteklemiyor.',
      dtmfFailed: 'Tuş sesi gönderilemedi.', mute: 'Sessize al', unmute: 'Mikrofonu aç',
      audioBlocked: 'Aramayı duymak için ses oynatıcısını kullanın.',
      disconnectedPage: 'Yeniden başlamak için Bağlan düğmesine basın.',
      elapsed: 'Arama süresi', unknownCaller: 'Bilinmeyen numara'
    }
  };

  function normalizeDialString(value) {
    const digits = String(value || '').replace(/[۰-۹٠-٩]/g, (digit) =>
      String('۰۱۲۳۴۵۶۷۸۹'.includes(digit) ? '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit) : '٠١٢٣٤٥٦٧٨٩'.indexOf(digit))
    ).replace(/[\s()\-]/g, '');
    return /^(?:\+?[0-9]{2,20}|\*[0-9*#]{1,15})$/.test(digits) ? digits : null;
  }

  function preferPcmu(connection, win = global) {
    // Use the browser's codec API, without rewriting ICE/DTLS/SDP. FreeSWITCH
    // restricts the negotiated server codec to PCMU when this API is unavailable.
    if (!connection || typeof connection.getTransceivers !== 'function' ||
        !win.RTCRtpSender || typeof win.RTCRtpSender.getCapabilities !== 'function') return;
    const capabilities = win.RTCRtpSender.getCapabilities('audio');
    const codecs = capabilities && capabilities.codecs || [];
    const allowed = codecs.filter((codec) => /^(?:audio\/PCMU|audio\/telephone-event)$/i.test(codec.mimeType) && codec.clockRate === 8000);
    if (!allowed.some((codec) => /^audio\/PCMU$/i.test(codec.mimeType))) return;
    connection.getTransceivers().forEach((transceiver) => {
      const track = transceiver.sender && transceiver.sender.track || transceiver.receiver && transceiver.receiver.track;
      if (!track || track.kind !== 'audio' || typeof transceiver.setCodecPreferences !== 'function') return;
      try { transceiver.setCodecPreferences(allowed); } catch (_) { /* server still enforces PCMU */ }
    });
  }

  function mount(root, win = global) {
    if (!root || root.dataset.voiceMounted === 'true') return null;
    root.dataset.voiceMounted = 'true';
    const doc = root.ownerDocument;
    const language = (root.dataset.lang || doc.documentElement.lang || 'fa').split('-')[0];
    const text = messages[language] || messages.fa;
    const elements = {};
    for (const id of ['extension', 'connect', 'disconnect', 'call', 'hangup', 'mute', 'answer', 'reject',
      'destination', 'status', 'call-status', 'remote-number', 'remote-audio']) {
      elements[id] = root.querySelector('#' + id);
    }
    if (Object.values(elements).some((element) => !element)) return null;

    let ua = null;
    let socket = null;
    let session = null;
    let media = null;
    let domain = '';
    let registered = false;
    let connecting = false;
    let acquiringMedia = false;
    let accepting = false;
    let muted = false;
    let generation = 0;
    let mediaGeneration = 0;
    let controller = null;
    let connectionTimer = null;
    let durationTimer = null;
    let callStarted = null;
    let currentCallLabel = '';
    const tones = Array.from(root.querySelectorAll('[data-tone]'));

    function status(key, kind = 'neutral') {
      elements.status.textContent = text[key];
      elements.status.dataset.state = kind;
      root.dataset.state = kind;
    }
    function callStatus(key) {
      currentCallLabel = key;
      elements['call-status'].textContent = text[key];
    }
    function updateControls() {
      const active = Boolean(session);
      const established = Boolean(session && session.isEstablished());
      const incoming = Boolean(session && session.direction === 'incoming' && !established && !accepting);
      elements.connect.disabled = connecting || Boolean(ua) || !elements.extension.value;
      elements.disconnect.disabled = !connecting && !ua;
      elements.extension.disabled = connecting || Boolean(ua);
      elements.call.disabled = !registered || active || acquiringMedia;
      elements.destination.disabled = active || acquiringMedia;
      elements.hangup.disabled = !active && !acquiringMedia;
      elements.mute.disabled = !established;
      elements.mute.setAttribute('aria-pressed', String(muted));
      elements.mute.textContent = muted ? text.unmute : text.mute;
      elements.answer.disabled = !incoming || acquiringMedia;
      elements.reject.disabled = !incoming || acquiringMedia;
      elements.answer.hidden = !incoming && !accepting;
      elements.reject.hidden = !incoming && !accepting;
      tones.forEach((button) => { button.disabled = active ? !established : acquiringMedia; });
      root.dataset.inCall = String(active || acquiringMedia);
    }
    function stopTracks(stream) {
      if (stream && typeof stream.getTracks === 'function') {
        stream.getTracks().forEach((track) => { try { track.stop(); } catch (_) { /* already stopped */ } });
      }
    }
    function clearMedia() {
      mediaGeneration += 1;
      stopTracks(media);
      media = null;
      acquiringMedia = false;
      accepting = false;
      muted = false;
      elements['remote-audio'].pause();
      elements['remote-audio'].srcObject = null;
      elements['remote-audio'].controls = false;
      win.clearInterval(durationTimer);
      durationTimer = null;
      callStarted = null;
    }
    function releaseCall(endedSession, label = 'ended') {
      if (session !== endedSession) return;
      const connection = session && session.connection;
      if (connection && typeof connection.getSenders === 'function') {
        connection.getSenders().forEach((sender) => { if (sender.track) stopTracks({ getTracks: () => [sender.track] }); });
      }
      session = null;
      clearMedia();
      callStatus(label);
      updateControls();
    }
    function disconnect(label = 'offline', kind = 'neutral') {
      generation += 1;
      connecting = false;
      registered = false;
      domain = '';
      if (controller) controller.abort();
      controller = null;
      win.clearTimeout(connectionTimer);
      connectionTimer = null;
      const oldSession = session;
      session = null;
      if (oldSession) { try { oldSession.terminate(); } catch (_) { /* already terminated */ } }
      clearMedia();
      const oldUa = ua;
      const oldSocket = socket;
      ua = null;
      socket = null;
      if (oldUa) {
        try { oldUa.stop(); } catch (_) { /* transport already closed */ }
        try { oldUa.set('password', ''); } catch (_) { /* drop our reference below */ }
      }
      if (oldSocket) { try { oldSocket.disconnect(); } catch (_) { /* already disconnected */ } }
      elements['remote-number'].textContent = '';
      status(label, kind);
      callStatus('disconnectedPage');
      updateControls();
    }
    function attachRemote(connection, activeSession) {
      connection.addEventListener('track', (event) => {
        if (session !== activeSession || event.track.kind !== 'audio') return;
        const audio = elements['remote-audio'];
        audio.srcObject = event.streams && event.streams[0]
          ? event.streams[0] : new win.MediaStream([event.track]);
        const playing = audio.play();
        if (playing && typeof playing.catch === 'function') playing.catch(() => {
          if (session !== activeSession) return;
          audio.controls = true;
          callStatus('audioBlocked');
        });
      });
    }
    function failureLabel(event) {
      const cause = event && typeof event.cause === 'string' ? event.cause : '';
      const statusCode = Number(event && event.message && event.message.status_code);
      const errorName = event && event.error && event.error.name;
      if (errorName === 'NotAllowedError' || /user denied media access/i.test(cause)) return 'microphoneDenied';
      if (statusCode === 488 || /\b(?:sdp|webrtc|ice|media|rtp|codec)\b/i.test(cause) ||
          errorName === 'OperationError' || errorName === 'NotSupportedError') return 'mediaFailed';
      if (/busy/i.test(cause)) return 'busy';
      if (/reject|denied/i.test(cause)) return 'rejected';
      if (/no answer|request timeout|unavailable/i.test(cause)) return 'noAnswer';
      if (/canceled|terminated/i.test(cause)) return 'ended';
      return 'callFailed';
    }
    function attachSession(nextSession) {
      if (session === nextSession) return;
      if (session || (acquiringMedia && nextSession.direction === 'incoming')) {
        try { nextSession.terminate({ status_code: 486, reason_phrase: 'Busy Here' }); } catch (_) { /* ended */ }
        return;
      }
      session = nextSession;
      accepting = false;
      const identity = nextSession.remote_identity;
      const number = identity && identity.uri && identity.uri.user;
      elements['remote-number'].textContent = String(number || text.unknownCaller).slice(0, 96);
      callStatus(nextSession.direction === 'incoming' ? 'incoming' : 'calling');
      nextSession.on('peerconnection', (event) => attachRemote(event.peerconnection, nextSession));
      // JsSIP fires connecting after adding local audio and before making SDP.
      nextSession.on('connecting', () => preferPcmu(nextSession.connection, win));
      nextSession.on('progress', () => { if (session === nextSession) callStatus('ringing'); });
      nextSession.on('confirmed', () => {
        if (session !== nextSession) return;
        accepting = false;
        callStarted = Date.now();
        callStatus('talking');
        durationTimer = win.setInterval(() => {
          if (session !== nextSession || !callStarted || currentCallLabel === 'audioBlocked') return;
          const elapsed = Math.floor((Date.now() - callStarted) / 1000);
          elements['call-status'].textContent = text.talking + ' · ' +
            String(Math.floor(elapsed / 60)).padStart(2, '0') + ':' + String(elapsed % 60).padStart(2, '0');
        }, 1000);
        updateControls();
      });
      nextSession.on('muted', () => { if (session === nextSession) { muted = true; updateControls(); } });
      nextSession.on('unmuted', () => { if (session === nextSession) { muted = false; updateControls(); } });
      nextSession.on('ended', () => releaseCall(nextSession));
      nextSession.on('failed', (event) => releaseCall(nextSession, failureLabel(event)));
      nextSession.on('getusermediafailed', () => releaseCall(nextSession, 'microphoneDenied'));
      if (nextSession.connection) attachRemote(nextSession.connection, nextSession);
      updateControls();
    }

    async function connect() {
      if (connecting || ua || !elements.extension.value) return;
      if (!win.isSecureContext || !win.RTCPeerConnection || !win.navigator.mediaDevices) {
        status('unavailable', 'error'); return;
      }
      if (!win.JsSIP || !win.JsSIP.UA || !win.JsSIP.WebSocketInterface) {
        status('unavailableLibrary', 'error'); return;
      }
      if (!root.dataset.tokenName || !root.dataset.tokenHash) { status('expired', 'error'); return; }
      const attempt = ++generation;
      connecting = true;
      status('connecting', 'connecting');
      updateControls();
      controller = new win.AbortController();
      connectionTimer = win.setTimeout(() => {
        if (attempt === generation) disconnect('connectFailed', 'error');
      }, 25000);
      let responseData = null;
      let configuration = null;
      try {
        const form = new win.FormData();
        form.append('action', 'connect');
        form.append('extension_uuid', elements.extension.value);
        form.append(root.dataset.tokenName, root.dataset.tokenHash);
        const response = await win.fetch('api.php', {
          method: 'POST', credentials: 'same-origin', cache: 'no-store',
          headers: { Accept: 'application/json' }, body: form, signal: controller.signal,
          redirect: 'error'
        });
        if (attempt !== generation) return;
        if (!response.ok) {
          disconnect(response.status === 401 || response.status === 403 ? 'expired' :
            response.status === 429 ? 'rateLimited' : 'connectFailed', 'error');
          return;
        }
        responseData = await response.json();
        if (attempt !== generation) return;
        if (responseData && responseData.csrf &&
            typeof responseData.csrf.name === 'string' && responseData.csrf.name.length <= 128 &&
            typeof responseData.csrf.hash === 'string' && responseData.csrf.hash.length <= 512 &&
            responseData.csrf.name && responseData.csrf.hash) {
          root.dataset.tokenName = responseData.csrf.name;
          root.dataset.tokenHash = responseData.csrf.hash;
        }
        const sip = responseData && responseData.ok === true && responseData.sip;
        const identity = sip && typeof sip.uri === 'string' &&
          /^sip:([a-z0-9_.+\-]{1,64})@([a-z0-9.\-]{1,253})$/i.exec(sip.uri);
        if (!identity || typeof sip.domain !== 'string' || identity[2] !== sip.domain ||
            !/^[a-z0-9.\-]{1,253}$/i.test(sip.domain) ||
            !/^[a-z0-9_.\-]{1,128}$/i.test(sip.authorization_user || '') ||
            typeof sip.password !== 'string' || !sip.password || sip.password.length > 512) {
          throw new Error('Invalid phone configuration');
        }
        domain = sip.domain;
        if (win.JsSIP.debug && typeof win.JsSIP.debug.disable === 'function') win.JsSIP.debug.disable();
        socket = new win.JsSIP.WebSocketInterface(WSS_URL);
        // Browser WSS keeps TLS; Nginx forwards to FreeSWITCH over loopback WS.
        socket.via_transport = 'WS';
        configuration = {
          sockets: [socket], uri: sip.uri, authorization_user: sip.authorization_user,
          password: sip.password, display_name: String(sip.display_name || responseData.extension || '').slice(0, 128),
          register: true, register_expires: 120, session_timers: false,
          connection_recovery_min_interval: 30, connection_recovery_max_interval: 30
        };
        const activeUa = new win.JsSIP.UA(configuration);
        ua = activeUa;
        // JsSIP copies these fields. Do not retain a second credential object.
        configuration.password = '';
        configuration = null;
        sip.password = '';
        responseData = null;
        controller = null;
        activeUa.on('connected', () => {
          if (ua === activeUa && attempt === generation) status('registering', 'connecting');
        });
        activeUa.on('registered', () => {
          if (ua !== activeUa || attempt !== generation) return;
          connecting = false;
          registered = true;
          win.clearTimeout(connectionTimer);
          connectionTimer = null;
          status('ready', 'ready');
          if (!session) callStatus('ready');
          updateControls();
        });
        activeUa.on('registrationFailed', () => {
          if (ua === activeUa) disconnect('connectFailed', 'error');
        });
        activeUa.on('unregistered', () => {
          if (ua === activeUa) disconnect('disconnected', 'error');
        });
        activeUa.on('disconnected', () => {
          if (ua === activeUa) disconnect('disconnected', 'error');
        });
        activeUa.on('newRTCSession', (event) => {
          if (ua !== activeUa || attempt !== generation || !registered) {
            try { event.session.terminate({ status_code: 480 }); } catch (_) { /* ended */ }
            return;
          }
          attachSession(event.session);
        });
        activeUa.start();
      } catch (_) {
        if (attempt === generation) disconnect('connectFailed', 'error');
      } finally {
        if (configuration) configuration.password = '';
        if (responseData && responseData.sip) responseData.sip.password = '';
        responseData = null;
        configuration = null;
      }
    }

    async function getMicrophone() {
      const attempt = ++mediaGeneration;
      acquiringMedia = true;
      callStatus('microphone');
      updateControls();
      try {
        const stream = await win.navigator.mediaDevices.getUserMedia({ audio: true, video: false });
        if (attempt !== mediaGeneration || !registered || !ua) { stopTracks(stream); return null; }
        media = stream;
        acquiringMedia = false;
        return stream;
      } catch (_) {
        if (attempt === mediaGeneration) {
          acquiringMedia = false;
          callStatus('microphoneDenied');
          updateControls();
        }
        return null;
      }
    }
    async function call() {
      if (!registered || !ua || session || acquiringMedia) return;
      const number = normalizeDialString(elements.destination.value);
      if (!number) { callStatus('invalidNumber'); elements.destination.focus(); return; }
      elements.destination.value = number;
      const activeUa = ua;
      const stream = await getMicrophone();
      if (!stream) return;
      if (ua !== activeUa || session) {
        stopTracks(stream);
        if (media === stream) media = null;
        updateControls();
        return;
      }
      try {
        const outgoing = activeUa.call('sip:' + number + '@' + domain, {
          mediaStream: stream, mediaConstraints: { audio: true, video: false },
          rtcOfferConstraints: { offerToReceiveAudio: true, offerToReceiveVideo: false },
          pcConfig: mediaConfiguration()
        });
        if (session !== outgoing) attachSession(outgoing);
      } catch (error) {
        const label = failureLabel({ error });
        const activeSession = session;
        if (activeSession) {
          releaseCall(activeSession, label);
          try { activeSession.terminate(); } catch (_) { /* already ended */ }
        } else {
          clearMedia();
          callStatus(label);
          updateControls();
        }
      }
    }
    async function answer() {
      if (!session || session.direction !== 'incoming' || session.isEstablished() || accepting || acquiringMedia) return;
      const activeSession = session;
      accepting = true;
      const stream = await getMicrophone();
      if (!stream || session !== activeSession) {
        if (session === activeSession) { accepting = false; updateControls(); }
        return;
      }
      try {
        activeSession.answer({ mediaStream: stream, mediaConstraints: { audio: true, video: false },
          rtcAnswerConstraints: { offerToReceiveAudio: true, offerToReceiveVideo: false },
          pcConfig: mediaConfiguration() });
        callStatus('calling');
        updateControls();
      } catch (error) {
        releaseCall(activeSession, failureLabel({ error }));
        try { activeSession.terminate({ status_code: 480 }); } catch (_) { /* already ended */ }
      }
    }
    function hangup(reject = false) {
      const activeSession = session;
      if (activeSession) {
        try { activeSession.terminate(reject ? { status_code: 486, reason_phrase: 'Busy Here' } : {}); }
        catch (_) { /* already ended */ }
        releaseCall(activeSession, reject ? 'rejected' : 'ended');
      } else {
        clearMedia();
        callStatus('ended');
        updateControls();
      }
    }
    function tone(value) {
      if (!/^[0-9*#]$/.test(value)) return;
      if (session && session.isEstablished()) {
        try { session.sendDTMF(value, { transportType: 'RFC2833', duration: 160, interToneGap: 120 }); }
        catch (_) {
          try { session.sendDTMF(value, { transportType: 'INFO', duration: 160 }); }
          catch (_) { callStatus('dtmfFailed'); }
        }
      } else if (!session && !acquiringMedia && elements.destination.value.length < 24) {
        elements.destination.value += value;
      }
    }

    elements.connect.addEventListener('click', connect);
    elements.disconnect.addEventListener('click', () => disconnect());
    elements.call.addEventListener('click', call);
    elements.answer.addEventListener('click', answer);
    elements.reject.addEventListener('click', () => hangup(true));
    elements.hangup.addEventListener('click', () => hangup());
    elements.extension.addEventListener('change', updateControls);
    elements.mute.addEventListener('click', () => {
      if (!session || !session.isEstablished()) return;
      try {
        if (muted) session.unmute({ audio: true }); else session.mute({ audio: true });
        muted = Boolean(session.isMuted().audio);
        updateControls();
      } catch (_) { /* call may have ended during the click */ }
    });
    tones.forEach((button) => button.addEventListener('click', () => tone(button.dataset.tone)));
    root.addEventListener('keydown', (event) => {
      if (event.ctrlKey || event.metaKey || event.altKey) return;
      if (event.key === 'Enter' && event.target === elements.destination) {
        event.preventDefault(); call();
      } else if (event.key === 'Escape' && (session || acquiringMedia)) {
        event.preventDefault(); hangup();
      }
    });
    win.addEventListener('pagehide', () => disconnect());
    win.addEventListener('beforeunload', () => disconnect());
    // Returning from the browser back/forward cache must require Connect again.
    win.addEventListener('pageshow', (event) => { if (event.persisted) disconnect(); });
    status('offline');
    callStatus('disconnectedPage');
    updateControls();
    return { disconnect };
  }

  if (typeof module === 'object' && module.exports) module.exports = { normalizeDialString, preferPcmu, mount };
  if (global.document) {
    const start = () => mount(global.document.getElementById('voice-app'), global);
    if (global.document.readyState === 'loading') global.document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
  }
})(typeof window !== 'undefined' ? window : globalThis);
