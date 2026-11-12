package cloud.servernet.voice

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.SystemBarStyle
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.appcompat.app.AppCompatDelegate
import androidx.compose.runtime.getValue
import androidx.core.content.ContextCompat
import androidx.core.os.LocaleListCompat
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import cloud.servernet.voice.data.PhoneError
import cloud.servernet.voice.phone.VoiceService
import cloud.servernet.voice.ui.*

class MainActivity : AppCompatActivity() {
    private val app get() = application as VoiceApplication
    private val permissions = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) startPhone()
        else app.phone.error(PhoneError.MICROPHONE)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        val language = getSharedPreferences("voice-ui", MODE_PRIVATE).getString("language", "fa") ?: "fa"
        if (AppCompatDelegate.getApplicationLocales().toLanguageTags() != language)
            AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags(language))
        super.onCreate(savedInstanceState)
        enableEdgeToEdge(statusBarStyle = SystemBarStyle.dark(android.graphics.Color.TRANSPARENT),
            navigationBarStyle = SystemBarStyle.dark(android.graphics.Color.TRANSPARENT))
        val repo = app.repository
        if (repo.unreadable) app.phone.error(PhoneError.STORAGE)
        setContent {
            val profiles by repo.profiles.collectAsStateWithLifecycle()
            val selected by repo.selected.collectAsStateWithLifecycle()
            val contacts by repo.contacts.collectAsStateWithLifecycle()
            val calls by repo.calls.collectAsStateWithLifecycle()
            val phone by app.phone.state.collectAsStateWithLifecycle()
            VoiceScreen(VoiceUiModel(profiles, selected, phone, contacts, calls, language), VoiceActions(
                connect = { connect() }, disconnect = { VoiceService.stop(this) }, dial = app.phone::dial,
                select = { id ->
                    if (app.phone.disconnect()) {
                        runCatching { repo.select(id) }.onSuccess { connect() }.onFailure { app.phone.error(PhoneError.STORAGE) }
                    }
                },
                saveProfile = { profile ->
                    if (!app.phone.disconnect()) false else runCatching {
                        repo.save(profile)
                        stopService(android.content.Intent(this, VoiceService::class.java))
                        true
                    }.getOrElse { app.phone.error(PhoneError.STORAGE); false }
                },
                removeProfile = { id ->
                    if (phone.call != null) app.phone.error(PhoneError.BUSY)
                    else runCatching {
                        if (selected == id) { app.phone.disconnect(); stopService(android.content.Intent(this, VoiceService::class.java)) }
                        repo.remove(id)
                    }.onFailure { app.phone.error(PhoneError.STORAGE) }
                },
                saveContact = { contact -> runCatching { repo.addContact(contact); true }.getOrElse { app.phone.error(PhoneError.STORAGE); false } },
                removeContact = { contact -> runCatching { repo.removeContact(contact.id, contact.profileId) }.onFailure { app.phone.error(PhoneError.STORAGE) } },
                favorite = { contact -> runCatching { repo.favorite(contact.id, contact.profileId) }.onFailure { app.phone.error(PhoneError.STORAGE) } },
                language = { code ->
                    if (code in setOf("fa", "en", "tr")) {
                        getSharedPreferences("voice-ui", MODE_PRIVATE).edit().putString("language", code).apply()
                        AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags(code))
                    }
                },
                dismissError = app.phone::dismissError, answer = app.phone::answer, hangUp = app.phone::hangUp,
                mute = app.phone::toggleMute, speaker = app.phone::toggleSpeaker, hold = app.phone::hold, dtmf = app.phone::dtmf,
            ))
        }
    }

    private fun connect() {
        if (app.repository.selectedProfile() == null) { app.phone.error(PhoneError.ACCOUNT); return }
        val needed = buildList {
            add(Manifest.permission.RECORD_AUDIO)
            if (Build.VERSION.SDK_INT >= 33) add(Manifest.permission.POST_NOTIFICATIONS)
            if (Build.VERSION.SDK_INT >= 31) add(Manifest.permission.BLUETOOTH_CONNECT)
        }.filter { ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED }
        if (needed.isEmpty()) startPhone() else permissions.launch(needed.toTypedArray())
    }
    private fun startPhone() {
        runCatching { VoiceService.start(this) }.onFailure { app.phone.error(PhoneError.CALL) }
    }
}
