package cloud.servernet.voice.phone

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.net.ConnectivityManager
import android.os.SystemClock
import android.telecom.Connection
import android.telecom.DisconnectCause
import androidx.core.content.ContextCompat
import cloud.servernet.voice.BuildConfig
import cloud.servernet.voice.data.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import org.linphone.core.*
import java.util.UUID

/** All SDK calls run on Android's main thread, as required by liblinphone. */
class VoiceController(private val context: Context, private val repository: VoiceRepository) {
    private val _state = MutableStateFlow(PhoneState())
    val state = _state.asStateFlow()
    private var core: Core? = null
    private var nativeCall: Call? = null
    private var trackedPointer: Long? = null
    private var trackedId = ""
    private var callTime = 0L
    private var connectedAt = 0L
    private var pendingDial: PendingDial? = null
    internal var connection: Connection? = null
    private data class PendingDial(val token: String, val profileId: String, val number: String, val at: Long)

    fun error(error: PhoneError) { _state.update { it.copy(error = error) } }
    fun dismissError() { _state.update { it.copy(error = null) } }

    internal fun connectNow() {
        if (!ProfileScope.maySwitch(_state.value.call)) { error(PhoneError.BUSY); return }
        val profile = repository.selectedProfile() ?: run { error(PhoneError.ACCOUNT); return }
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            error(PhoneError.MICROPHONE); return
        }
        if (context.getSystemService(ConnectivityManager::class.java).activeNetwork == null) {
            error(PhoneError.NETWORK); return
        }
        _state.value = PhoneState(Registration.CONNECTING, profile.id)
        runCatching {
            profile.validate()
            val factory = Factory.instance()
            factory.setDebugMode(false, "ServerNetVoice")
            core?.stop()
            // A memory-only config prevents liblinphone from persisting plaintext SIP credentials.
            val config = factory.createConfigFromString(
                "[sip]\nstore_auth_info=0\n[logging]\nlog_collection_enabled=0\n" +
                    "[storage]\nuri=null\ncall_logs_db_uri=null\nfriends_db_uri=null\nzrtp_secrets_db_uri=null\n" +
                    "[lime]\nenabled=0\n"
            )
            val engine = factory.createCoreWithConfig(config, context)
            core = engine
            engine.setUserAgent("ServerNetVoice", BuildConfig.VERSION_NAME)
            engine.verifyServerCertificates(true)
            engine.maxCalls = 1
            engine.addListener(listener)
            val endpoint = SipEndpoint.parse(profile.server, profile.transport)
            val identity = requireNotNull(factory.createAddress("sip:${profile.username}@${endpoint.host}"))
            val server = requireNotNull(factory.createAddress("sip:${endpoint.authority}"))
            server.transport = when (profile.transport) {
                SipTransport.TLS -> TransportType.Tls
                SipTransport.TCP -> TransportType.Tcp
                SipTransport.UDP -> TransportType.Udp
            }
            val params = engine.createAccountParams()
            check(params.setIdentityAddress(identity) == 0)
            check(params.setServerAddress(server) == 0)
            check(params.setRoutesAddresses(arrayOf(server)) == 0)
            params.setRegisterEnabled(true)
            params.idkey = profile.id
            val auth = factory.createAuthInfo(profile.username, null, profile.password, null, null, endpoint.host, null)
            engine.addAuthInfo(auth)
            val account = engine.createAccount(params)
            check(engine.addAccount(account) == 0)
            engine.defaultAccount = account
            check(engine.start() == 0)
        }.onFailure {
            core?.stop(); core = null
            _state.update { it.copy(registration = Registration.FAILED, error = PhoneError.REGISTRATION) }
        }
        VoiceService.refresh(context)
    }

    fun disconnect(): Boolean {
        if (_state.value.call != null) { error(PhoneError.BUSY); return false }
        core?.let { it.clearAccounts(); it.clearAllAuthInfo(); it.stop() }
        core = null
        pendingDial = null
        _state.value = PhoneState()
        return true
    }

    fun dial(raw: String) {
        if (_state.value.call != null) { error(PhoneError.BUSY); return }
        if (pendingDial?.let { SystemClock.elapsedRealtime() - it.at in 0..15_000 } == true) { error(PhoneError.BUSY); return }
        if (_state.value.registration != Registration.ONLINE || repository.selected.value != _state.value.profileId) {
            error(PhoneError.ACCOUNT); return
        }
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            error(PhoneError.MICROPHONE); return
        }
        val number = runCatching { DialPlan.normalize(raw) }.getOrElse { error(PhoneError.INVALID_NUMBER); return }
        val pending = PendingDial(UUID.randomUUID().toString(), requireNotNull(_state.value.profileId), number, SystemClock.elapsedRealtime())
        pendingDial = pending
        runCatching { VoiceTelecom.place(context, number, pending.token) }.onFailure { pendingDial = null; error(PhoneError.CALL) }
    }

    internal fun performDial(number: String, token: String?): Boolean = runCatching {
        val pending = requireNotNull(pendingDial)
        pendingDial = null // Each UI-originated authorization is single-use.
        check(token == pending.token && SystemClock.elapsedRealtime() - pending.at in 0..15_000)
        check(pending.profileId == repository.selected.value && pending.number == number)
        val engine = requireNotNull(core)
        val account = requireNotNull(engine.defaultAccount)
        check(_state.value.registration == Registration.ONLINE && _state.value.call == null)
        check(account.params.idkey == repository.selected.value)
        val normalized = DialPlan.normalize(number)
        val target = requireNotNull(account.normalizeSipUri(normalized))
        val params = requireNotNull(engine.createCallParams(null))
        params.setVideoEnabled(false)
        params.account = account
        checkNotNull(engine.inviteAddressWithParams(target, params))
        true
    }.getOrElse { error(PhoneError.CALL); false }

    fun answer() {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            error(PhoneError.MICROPHONE); return
        }
        if (_state.value.call?.phase == CallPhase.INCOMING && nativeCall?.accept() != 0) error(PhoneError.CALL)
    }
    fun hangUp() { nativeCall?.let { if (it.state == Call.State.IncomingReceived) it.decline(Reason.Declined) else it.terminate() } }
    fun toggleMute() {
        val call = nativeCall ?: return
        call.microphoneMuted = !call.microphoneMuted
        _state.update { it.copy(call = it.call?.copy(muted = call.microphoneMuted)) }
    }
    fun speaker(enabled: Boolean) {
        val engine = core ?: return
        val device = engine.audioDevices.firstOrNull { it.type == if (enabled) AudioDevice.Type.Speaker else AudioDevice.Type.Earpiece }
            ?: return
        engine.outputAudioDevice = device
        _state.update { it.copy(call = it.call?.copy(speaker = enabled)) }
    }
    fun toggleSpeaker() = speaker(!(_state.value.call?.speaker ?: false))
    fun hold() = setHeld(_state.value.call?.phase != CallPhase.HELD)
    fun setHeld(held: Boolean) {
        val call = nativeCall ?: return
        val phase = _state.value.call?.phase
        if (held && phase != CallPhase.ACTIVE || !held && phase != CallPhase.HELD) return
        val result = if (held) call.pause() else call.resume()
        if (result != 0) error(PhoneError.CALL)
    }
    fun dtmf(char: Char) { if (char in "0123456789*#") nativeCall?.sendDtmf(char) }

    private val listener = object : CoreListenerStub() {
        override fun onAccountRegistrationStateChanged(core: Core, account: Account, state: RegistrationState, message: String) {
            if (account.params.idkey != _state.value.profileId || core != this@VoiceController.core) return
            val registration = when (state) {
                RegistrationState.Ok -> Registration.ONLINE
                RegistrationState.Progress, RegistrationState.Refreshing -> Registration.CONNECTING
                RegistrationState.Failed -> Registration.FAILED
                else -> Registration.OFFLINE
            }
            _state.update { it.copy(registration = registration, error = if (state == RegistrationState.Failed) PhoneError.REGISTRATION else null) }
            VoiceService.refresh(context)
        }

        override fun onCallStateChanged(core: Core, call: Call, state: Call.State, message: String) {
            if (core != this@VoiceController.core) return
            val starting = state == Call.State.IncomingReceived || state == Call.State.OutgoingInit
            if (starting && trackedPointer != null && trackedPointer != call.nativePointer) {
                call.decline(Reason.Busy); return
            }
            if (starting && trackedPointer == null) {
                val profileId = _state.value.profileId ?: return
                trackedPointer = call.nativePointer; trackedId = UUID.randomUUID().toString()
                nativeCall = call; callTime = System.currentTimeMillis(); connectedAt = 0
                _state.update { it.copy(call = LiveCall(trackedId, profileId, call.remoteAddress.username ?: "", state == Call.State.IncomingReceived, if (state == Call.State.IncomingReceived) CallPhase.INCOMING else CallPhase.DIALING), error = null) }
                if (state == Call.State.IncomingReceived) {
                    runCatching { VoiceTelecom.incoming(context, call.remoteAddress.username ?: "") }
                        .onFailure { call.decline(Reason.Busy) }
                    VoiceService.incoming(context, call.remoteAddress.username ?: "")
                }
            }
            if (trackedPointer != call.nativePointer) return
            val phase = when (state) {
                Call.State.IncomingReceived -> CallPhase.INCOMING
                Call.State.OutgoingInit, Call.State.OutgoingProgress -> CallPhase.DIALING
                Call.State.OutgoingRinging, Call.State.OutgoingEarlyMedia -> CallPhase.RINGING
                Call.State.Connected, Call.State.StreamsRunning, Call.State.Resuming -> CallPhase.ACTIVE
                Call.State.Paused, Call.State.PausedByRemote -> CallPhase.HELD
                Call.State.End, Call.State.Error, Call.State.Released -> CallPhase.ENDED
                else -> return
            }
            if (phase == CallPhase.ACTIVE && connectedAt == 0L) connectedAt = SystemClock.elapsedRealtime()
            if (phase == CallPhase.ENDED) {
                _state.value.call?.let { current ->
                    runCatching { repository.record(CallEntry(current.id, current.profileId, current.number, current.incoming, connectedAt != 0L, callTime, call.duration.coerceAtLeast(0))) }
                        .onFailure { error(PhoneError.STORAGE) }
                }
                connection?.setDisconnected(DisconnectCause(if (state == Call.State.Error) DisconnectCause.ERROR else DisconnectCause.LOCAL))
                connection?.destroy(); connection = null
                nativeCall = null; trackedPointer = null
                _state.update { it.copy(call = null, error = if (state == Call.State.Error) PhoneError.CALL else it.error) }
                VoiceService.dismissIncoming(context)
            } else {
                _state.update { it.copy(call = it.call?.copy(phase = phase, startedAt = connectedAt, quality = call.currentQuality)) }
                when (phase) {
                    CallPhase.ACTIVE -> { connection?.setActive(); VoiceService.dismissIncoming(context) }
                    CallPhase.HELD -> connection?.setOnHold()
                    CallPhase.RINGING, CallPhase.DIALING -> connection?.setDialing()
                    else -> Unit
                }
            }
            VoiceService.refresh(context)
        }
    }
}
