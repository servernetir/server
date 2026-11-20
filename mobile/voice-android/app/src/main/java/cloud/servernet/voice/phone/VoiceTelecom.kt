package cloud.servernet.voice.phone

import android.annotation.SuppressLint
import android.content.ComponentName
import android.content.Context
import android.net.Uri
import android.os.Bundle
import android.telecom.*
import cloud.servernet.voice.VoiceApplication

object VoiceTelecom {
    internal const val DIAL_TOKEN = "cloud.servernet.voice.DIAL_TOKEN"
    private fun handle(context: Context) = PhoneAccountHandle(ComponentName(context, VoiceConnectionService::class.java), "servernet-voice")
    fun register(context: Context) {
        context.getSystemService(TelecomManager::class.java).registerPhoneAccount(
            PhoneAccount.builder(handle(context), "ServerNet Voice").setCapabilities(PhoneAccount.CAPABILITY_SELF_MANAGED).build()
        )
    }
    // Explicit self-managed PhoneAccount uses MANAGE_OWN_CALLS, never the SIM's CALL_PHONE permission.
    @SuppressLint("MissingPermission")
    fun place(context: Context, number: String, token: String) {
        register(context)
        val telecom = context.getSystemService(TelecomManager::class.java)
        check(telecom.isOutgoingCallPermitted(handle(context)))
        telecom.placeCall(Uri.fromParts("tel", number, null), Bundle().apply {
            putParcelable(TelecomManager.EXTRA_PHONE_ACCOUNT_HANDLE, handle(context))
            putBundle(TelecomManager.EXTRA_OUTGOING_CALL_EXTRAS, Bundle().apply { putString(DIAL_TOKEN, token) })
        })
    }
    fun incoming(context: Context, number: String) {
        register(context)
        context.getSystemService(TelecomManager::class.java).addNewIncomingCall(handle(context), Bundle().apply {
            putParcelable(TelecomManager.EXTRA_INCOMING_CALL_ADDRESS, Uri.fromParts("tel", number, null))
        })
    }
}

class VoiceConnectionService : ConnectionService() {
    private val phone get() = (application as VoiceApplication).phone
    private fun create(request: ConnectionRequest, incoming: Boolean): Connection {
        val connection = object : Connection() {
            override fun onAnswer() = phone.answer()
            override fun onReject() = phone.hangUp()
            override fun onDisconnect() = phone.hangUp()
            override fun onAbort() = phone.hangUp()
            override fun onHold() = phone.setHeld(true)
            override fun onUnhold() = phone.setHeld(false)
            override fun onCallAudioStateChanged(state: CallAudioState?) {
                super.onCallAudioStateChanged(state)
                // Leave wired/Bluetooth routing to the SDK/platform instead of forcing an earpiece.
                when (state?.route) {
                    CallAudioState.ROUTE_SPEAKER -> phone.speaker(true)
                    CallAudioState.ROUTE_EARPIECE -> phone.speaker(false)
                    CallAudioState.ROUTE_BLUETOOTH, CallAudioState.ROUTE_WIRED_HEADSET -> Unit
                    else -> Unit
                }
            }
        }
        connection.connectionProperties = Connection.PROPERTY_SELF_MANAGED
        connection.connectionCapabilities = Connection.CAPABILITY_HOLD or Connection.CAPABILITY_SUPPORT_HOLD or Connection.CAPABILITY_MUTE
        connection.setAudioModeIsVoip(true)
        connection.setAddress(request.address, TelecomManager.PRESENTATION_ALLOWED)
        phone.connection = connection
        if (incoming) connection.setRinging() else {
            connection.setDialing()
            if (!phone.performDial(request.address?.schemeSpecificPart ?: "", request.extras?.getString(VoiceTelecom.DIAL_TOKEN))) {
                connection.setDisconnected(DisconnectCause(DisconnectCause.ERROR)); connection.destroy(); phone.connection = null
            }
        }
        return connection
    }
    override fun onCreateOutgoingConnection(connectionManagerPhoneAccount: PhoneAccountHandle?, request: ConnectionRequest) = create(request, false)
    override fun onCreateIncomingConnection(connectionManagerPhoneAccount: PhoneAccountHandle?, request: ConnectionRequest) = create(request, true)
    override fun onCreateOutgoingConnectionFailed(connectionManagerPhoneAccount: PhoneAccountHandle?, request: ConnectionRequest) { phone.error(cloud.servernet.voice.data.PhoneError.CALL) }
    override fun onCreateIncomingConnectionFailed(connectionManagerPhoneAccount: PhoneAccountHandle?, request: ConnectionRequest) { phone.hangUp() }
}
