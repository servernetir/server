package cloud.servernet.voice

import android.app.Application
import android.util.Base64
import androidx.test.core.app.ApplicationProvider
import cloud.servernet.voice.data.*
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.io.File
import javax.crypto.spec.SecretKeySpec

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [35], application = Application::class)
class VoiceRepositoryTest {
    private val context get() = ApplicationProvider.getApplicationContext<Application>()
    private val key = SecretKeySpec(ByteArray(32) { (it + 1).toByte() }, "AES")
    private val profile = SipProfile(id = "business-a", business = "Private business", username = "private-user", password = "PRIVATE-PASSWORD", server = "pbx.example.test:7102")
    private val file get() = File(context.filesDir, "voice-vault-v1.enc")
    @Before fun clean() { context.filesDir.listFiles()?.filter { it.name.startsWith("voice-vault-v1.enc") }?.forEach { it.delete() } }
    private fun repo() = VoiceRepository(context) { key }
    @Test fun `encrypted accounts contacts and history survive a process restart`() {
        val first = repo()
        first.save(profile)
        first.addContact(Contact(profileId = profile.id, name = "PRIVATE-CUSTOMER", number = "101"))
        first.record(CallEntry("PRIVATE-CALL-IDENTIFIER", profile.id, "101", false, true, 123, 37))
        val bytes = file.readText()
        listOf(profile.username, profile.password, profile.business, "PRIVATE-CUSTOMER", "PRIVATE-CALL-IDENTIFIER").forEach { assertFalse(bytes.contains(it)) }
        val restored = repo()
        assertEquals(profile, restored.selectedProfile())
        assertEquals("PRIVATE-CUSTOMER", restored.contacts.value.single().name)
        assertEquals(37, restored.calls.value.single().durationSeconds)
    }
    @Test fun `authenticated encryption detects tampering and never overwrites unreadable data`() {
        repo().save(profile)
        val json = JSONObject(file.readText())
        val ciphertext = Base64.decode(json.getString("data"), Base64.NO_WRAP)
        ciphertext[0] = (ciphertext[0].toInt() xor 1).toByte()
        file.writeText(json.put("data", Base64.encodeToString(ciphertext, Base64.NO_WRAP)).toString())
        val damaged = file.readBytes()
        val restored = repo()
        assertTrue(restored.unreadable)
        assertThrows(IllegalStateException::class.java) { restored.save(profile.copy(id = "new")) }
        assertArrayEquals(damaged, file.readBytes())
    }
    @Test fun `removing one business preserves the other businesses data`() {
        val repository = repo()
        repository.save(profile)
        val second = profile.copy(id = "business-b", username = "user-b")
        repository.save(second)
        listOf(profile, second).forEach { p ->
            repository.addContact(Contact(profileId = p.id, name = p.id, number = "101"))
            repository.record(CallEntry(p.id, p.id, "101", false, true, 100, 1))
        }
        repository.remove(profile.id)
        val restored = repo()
        assertEquals(listOf(second), restored.profiles.value)
        assertEquals(second.id, restored.selected.value)
        assertEquals(second.id, restored.contacts.value.single().profileId)
        assertEquals(second.id, restored.calls.value.single().profileId)
    }
    @Test fun `late call completion cannot resurrect an account that was removed`() {
        val repository = repo()
        repository.save(profile)
        repository.remove(profile.id)
        repository.record(CallEntry("late", profile.id, "101", false, true, 100, 1))
        assertTrue(repo().calls.value.isEmpty())
    }
}
