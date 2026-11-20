package cloud.servernet.voice.phone

import android.app.*
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.IBinder
import android.os.Build
import android.content.res.Configuration
import androidx.core.app.NotificationCompat
import androidx.core.app.Person
import androidx.core.content.ContextCompat
import cloud.servernet.voice.MainActivity
import cloud.servernet.voice.R
import cloud.servernet.voice.VoiceApplication
import cloud.servernet.voice.data.Registration
import java.util.Locale

class VoiceService : Service() {
    private val phone get() = (application as VoiceApplication).phone
    override fun onBind(intent: Intent?): IBinder? = null
    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        channels(this)
        if (intent?.action == STOP) {
            if (phone.disconnect()) { stopForeground(STOP_FOREGROUND_REMOVE); stopSelf() }
            return START_NOT_STICKY
        }
        if (intent?.action == START) {
            if (Build.VERSION.SDK_INT >= 29) startForeground(100, status(this), ServiceInfo.FOREGROUND_SERVICE_TYPE_PHONE_CALL)
            else startForeground(100, status(this))
            phone.connectNow()
        }
        return START_NOT_STICKY
    }
    override fun onDestroy() { phone.disconnect(); dismissIncoming(this); super.onDestroy() }

    companion object {
        private const val START = "cloud.servernet.voice.START"
        private const val STOP = "cloud.servernet.voice.STOP"
        private const val CONNECTION = "voice_connection"
        private const val CALLS = "voice_calls"
        private fun text(context: Context, resource: Int): String {
            val language = context.getSharedPreferences("voice-ui", Context.MODE_PRIVATE).getString("language", "fa") ?: "fa"
            val config = Configuration(context.resources.configuration).apply { setLocale(Locale.forLanguageTag(language)) }
            return context.createConfigurationContext(config).getString(resource)
        }
        fun start(context: Context) {
            VoiceTelecom.register(context)
            ContextCompat.startForegroundService(context, Intent(context, VoiceService::class.java).setAction(START))
        }
        fun stop(context: Context) { context.startService(Intent(context, VoiceService::class.java).setAction(STOP)) }
        private fun open(context: Context) = PendingIntent.getActivity(context, 0, Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        private fun action(context: Context, action: String, code: Int) = PendingIntent.getBroadcast(context, code, Intent(context, CallActionReceiver::class.java).setAction(action), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        private fun channels(context: Context) {
            val manager = context.getSystemService(NotificationManager::class.java)
            manager.createNotificationChannel(NotificationChannel(CONNECTION, text(context, R.string.notification_channel), NotificationManager.IMPORTANCE_LOW))
            manager.createNotificationChannel(NotificationChannel(CALLS, text(context, R.string.call_channel), NotificationManager.IMPORTANCE_HIGH))
        }
        private fun status(context: Context): Notification {
            val state = (context.applicationContext as VoiceApplication).phone.state.value
            val status = when (state.registration) {
                Registration.ONLINE -> R.string.online
                Registration.CONNECTING -> R.string.connecting
                Registration.FAILED -> R.string.connection_failed
                Registration.OFFLINE -> R.string.offline
            }
            return NotificationCompat.Builder(context, CONNECTION).setSmallIcon(R.drawable.ic_voice_notification)
                .setContentTitle(text(context, R.string.app_name)).setContentText(text(context, if (state.call != null) R.string.hd_voice else status))
                .setContentIntent(open(context)).setOngoing(true).setSilent(true).setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
                .addAction(0, text(context, if (state.call != null) R.string.hang_up else R.string.disconnect), action(context, if (state.call != null) "hangup" else "disconnect", 1)).build()
        }
        fun refresh(context: Context) {
            channels(context)
            val phone = (context.applicationContext as VoiceApplication).phone
            if (phone.state.value.profileId != null) context.getSystemService(NotificationManager::class.java).notify(100, status(context))
        }
        fun incoming(context: Context, number: String) {
            channels(context)
            val notification = NotificationCompat.Builder(context, CALLS).setSmallIcon(R.drawable.ic_voice_notification)
                .setContentTitle(text(context, R.string.incoming_call)).setContentIntent(open(context))
                .setVisibility(NotificationCompat.VISIBILITY_PRIVATE).setCategory(NotificationCompat.CATEGORY_CALL).setOngoing(true)
                .setStyle(NotificationCompat.CallStyle.forIncomingCall(Person.Builder().setName(number.ifEmpty { text(context, R.string.incoming_call) }).setImportant(true).build(), action(context, "hangup", 2), action(context, "answer", 3)))
                .build()
            context.getSystemService(NotificationManager::class.java).notify(101, notification)
        }
        fun dismissIncoming(context: Context) = context.getSystemService(NotificationManager::class.java).cancel(101)
    }
}

class CallActionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val phone = (context.applicationContext as VoiceApplication).phone
        when (intent.action) {
            "answer" -> phone.answer()
            "hangup" -> phone.hangUp()
            "disconnect" -> VoiceService.stop(context)
        }
    }
}
