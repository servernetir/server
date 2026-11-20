package cloud.servernet.voice.data

import java.util.UUID

enum class SipTransport(val defaultPort: Int) { TLS(5061), TCP(5060), UDP(5060) }

data class SipEndpoint(val host: String, val port: Int) {
    val authority get() = "$host:$port"
    companion object {
        fun parse(value: String, transport: SipTransport): SipEndpoint {
            val input = value.trim()
            require(input.length in 1..253 && !input.any { it.isWhitespace() })
            val parts = input.split(':')
            require(parts.size in 1..2)
            val host = parts[0].lowercase()
            require(host.split('.').all { it.matches(Regex("[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?")) })
            val port = if (parts.size == 2) parts[1].toIntOrNull() else transport.defaultPort
            require(port != null && port in 1..65535)
            return SipEndpoint(host, port)
        }
    }
}

data class SipProfile(
    val id: String = UUID.randomUUID().toString(),
    val business: String,
    val username: String,
    val password: String,
    val server: String,
    val transport: SipTransport = SipTransport.TLS,
    val extension: String = "",
    val lineLabel: String = "",
) {
    fun validate() {
        require(business.trim().length in 1..80)
        require(username.matches(Regex("[A-Za-z0-9_.+\\-]{1,128}")))
        require(password.isNotEmpty() && password.length <= 512 && !password.contains('\u0000'))
        require(extension.isEmpty() || extension.matches(Regex("[0-9]{1,12}")))
        SipEndpoint.parse(server, transport)
    }
    override fun toString() = "SipProfile(id=$id, credentials=REDACTED)"
}

object DialPlan {
    /** No country rewriting: public numbers, extensions and PBX feature codes keep their meaning. */
    fun normalize(raw: String): String {
        require(!raw.contains('\r') && !raw.contains('\n'))
        val number = buildString {
            raw.forEach { c ->
                when {
                    c in '۰'..'۹' -> append('0' + (c - '۰'))
                    c in '٠'..'٩' -> append('0' + (c - '٠'))
                    c.isWhitespace() || c in "()-" -> Unit
                    else -> append(c)
                }
            }
        }
        require(number.length in 1..32)
        require(number.matches(Regex("\\+?[0-9*#]+")))
        return number
    }
}

enum class Registration { OFFLINE, CONNECTING, ONLINE, FAILED }
enum class CallPhase { DIALING, RINGING, INCOMING, ACTIVE, HELD, ENDED }
data class LiveCall(
    val id: String,
    val profileId: String,
    val number: String,
    val incoming: Boolean,
    val phase: CallPhase,
    val startedAt: Long = 0,
    val muted: Boolean = false,
    val speaker: Boolean = false,
    val quality: Float = -1f,
)
data class PhoneState(
    val registration: Registration = Registration.OFFLINE,
    val profileId: String? = null,
    val call: LiveCall? = null,
    val error: PhoneError? = null,
)
enum class PhoneError { ACCOUNT, REGISTRATION, NETWORK, CALL, MICROPHONE, BUSY, INVALID_NUMBER, STORAGE }
data class Contact(val id: String = UUID.randomUUID().toString(), val profileId: String, val name: String, val number: String, val favorite: Boolean = false, val extension: Boolean = false)
data class CallEntry(val id: String, val profileId: String, val number: String, val incoming: Boolean, val answered: Boolean, val at: Long, val durationSeconds: Int)

/** Every read/write is scoped to a profile, including callbacks arriving after an account switch. */
object ProfileScope {
    fun contacts(rows: List<Contact>, profileId: String?) = rows.filter { it.profileId == profileId }
    fun calls(rows: List<CallEntry>, profileId: String?) = rows.filter { it.profileId == profileId }
    fun maySwitch(call: LiveCall?) = call == null
}
