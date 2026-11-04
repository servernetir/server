package cloud.servernet.voice.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.AtomicFile
import android.util.Base64
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

class VoiceRepository internal constructor(context: Context, private val testKey: (() -> SecretKey)? = null) {
    private val file = AtomicFile(File(context.filesDir, "voice-vault-v1.enc"))
    private val _profiles = MutableStateFlow<List<SipProfile>>(emptyList())
    val profiles = _profiles.asStateFlow()
    private val _selected = MutableStateFlow<String?>(null)
    val selected = _selected.asStateFlow()
    private val _contacts = MutableStateFlow<List<Contact>>(emptyList())
    val contacts = _contacts.asStateFlow()
    private val _calls = MutableStateFlow<List<CallEntry>>(emptyList())
    val calls = _calls.asStateFlow()
    var unreadable = false
        private set

    init {
        if (file.baseFile.exists()) runCatching { decode(JSONObject(decrypt(file.readFully()))) }
            .onFailure { unreadable = true } // Never overwrite an unreadable vault with an empty one.
    }

    fun selectedProfile() = _profiles.value.firstOrNull { it.id == _selected.value }

    fun save(profile: SipProfile) {
        profile.validate()
        val profiles = _profiles.value.filterNot { it.id == profile.id } + profile
        commit(profiles = profiles, selected = profile.id)
    }
    fun select(id: String) {
        require(_profiles.value.any { it.id == id })
        commit(selected = id)
    }
    fun remove(id: String) {
        val profiles = _profiles.value.filterNot { it.id == id }
        commit(profiles, if (_selected.value == id) profiles.firstOrNull()?.id else _selected.value,
            _contacts.value.filterNot { it.profileId == id }, _calls.value.filterNot { it.profileId == id })
    }
    fun addContact(contact: Contact) {
        require(_profiles.value.any { it.id == contact.profileId })
        require(contact.name.trim().length in 1..80)
        val valid = contact.copy(number = DialPlan.normalize(contact.number))
        commit(contacts = _contacts.value.filterNot { it.id == valid.id } + valid)
    }
    fun removeContact(id: String, profileId: String) = commit(contacts = _contacts.value.filterNot { it.id == id && it.profileId == profileId })
    fun favorite(id: String, profileId: String) = commit(contacts = _contacts.value.map { if (it.id == id && it.profileId == profileId) it.copy(favorite = !it.favorite) else it })
    fun record(entry: CallEntry) {
        if (_profiles.value.none { it.id == entry.profileId }) return
        // Independent caps prevent one business from evicting another business's history.
        val own = (_calls.value.filter { it.profileId == entry.profileId && it.id != entry.id } + entry).takeLast(300)
        commit(calls = _calls.value.filterNot { it.profileId == entry.profileId } + own)
    }

    private fun commit(
        profiles: List<SipProfile> = _profiles.value,
        selected: String? = _selected.value,
        contacts: List<Contact> = _contacts.value,
        calls: List<CallEntry> = _calls.value,
    ) {
        check(!unreadable) { "Vault unavailable" }
        val root = JSONObject().put("version", 1).put("selected", selected)
            .put("profiles", JSONArray(profiles.map { p -> JSONObject().put("id", p.id).put("business", p.business)
                .put("username", p.username).put("password", p.password).put("server", p.server)
                .put("transport", p.transport.name).put("extension", p.extension).put("line", p.lineLabel) }))
            .put("contacts", JSONArray(contacts.map { c -> JSONObject().put("id", c.id).put("profile", c.profileId)
                .put("name", c.name).put("number", c.number).put("favorite", c.favorite).put("extension", c.extension) }))
            .put("calls", JSONArray(calls.map { c -> JSONObject().put("id", c.id).put("profile", c.profileId)
                .put("number", c.number).put("incoming", c.incoming).put("answered", c.answered).put("at", c.at).put("duration", c.durationSeconds) }))
        val data = encrypt(root.toString())
        val stream = file.startWrite()
        try { stream.write(data); file.finishWrite(stream) } catch (error: Exception) { file.failWrite(stream); throw error }
        _profiles.value = profiles; _selected.value = selected; _contacts.value = contacts; _calls.value = calls
    }
    private fun decode(root: JSONObject) {
        require(root.getInt("version") == 1)
        _profiles.value = root.getJSONArray("profiles").objects().map { p -> SipProfile(p.getString("id"), p.getString("business"), p.getString("username"), p.getString("password"), p.getString("server"), SipTransport.valueOf(p.getString("transport")), p.optString("extension"), p.optString("line")).also { it.validate() } }
        _selected.value = root.optString("selected").takeIf { id -> _profiles.value.any { it.id == id } }
        _contacts.value = root.getJSONArray("contacts").objects().map { c -> Contact(c.getString("id"), c.getString("profile"), c.getString("name"), c.getString("number"), c.getBoolean("favorite"), c.getBoolean("extension")) }
        _calls.value = root.getJSONArray("calls").objects().map { c -> CallEntry(c.getString("id"), c.getString("profile"), c.getString("number"), c.getBoolean("incoming"), c.getBoolean("answered"), c.getLong("at"), c.getInt("duration")) }
    }
    private fun key(): SecretKey {
        testKey?.let { return it() }
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (store.getKey("servernet.voice.v1", null) as? SecretKey)?.let { return it }
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").apply {
            init(KeyGenParameterSpec.Builder("servernet.voice.v1", KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build())
        }.generateKey()
    }
    private fun encrypt(value: String): ByteArray {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply { init(Cipher.ENCRYPT_MODE, key()) }
        return JSONObject().put("iv", Base64.encodeToString(cipher.iv, Base64.NO_WRAP))
            .put("data", Base64.encodeToString(cipher.doFinal(value.toByteArray(Charsets.UTF_8)), Base64.NO_WRAP)).toString().toByteArray()
    }
    private fun decrypt(data: ByteArray): String {
        val root = JSONObject(String(data, Charsets.UTF_8))
        val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply {
            init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, Base64.decode(root.getString("iv"), Base64.NO_WRAP)))
        }
        return String(cipher.doFinal(Base64.decode(root.getString("data"), Base64.NO_WRAP)), Charsets.UTF_8)
    }
    private fun JSONArray.objects() = (0 until length()).map { getJSONObject(it) }
}
