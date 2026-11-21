package com.babyparfume.babyparfume_mobile

import android.Manifest
import android.app.Activity
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import com.google.android.gms.common.ConnectionResult
import com.google.android.gms.common.GoogleApiAvailabilityLight
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import com.google.firebase.messaging.RemoteMessage

internal object AppPushNotifications {
    const val PERMISSION_REQUEST = 201
    private const val TOPIC = "oson_pos_announcements"
    private const val PRIVATE_CHANNEL = "oson_pos_account_updates"
    private const val PREFERENCES = "push_notifications"
    private const val PERMISSION_REQUESTED = "permission_requested"
    private var subscribed = false
    var tokenListener: (() -> Unit)? = null

    fun tokenChanged(context: Context) {
        subscribe(context, tokenChanged = true)
        tokenListener?.invoke()
    }

    fun onResume(activity: Activity) {
        if (!isAvailable(activity)) return
        createChannel(activity)
        val preferences = activity.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
        if (Build.VERSION.SDK_INT >= 33 &&
            activity.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED &&
            !preferences.getBoolean(PERMISSION_REQUESTED, false)
        ) {
            preferences.edit().putBoolean(PERMISSION_REQUESTED, true).apply()
            activity.requestPermissions(arrayOf(Manifest.permission.POST_NOTIFICATIONS), PERMISSION_REQUEST)
            return
        }
        subscribe(activity)
    }

    @Synchronized
    fun subscribe(context: Context, tokenChanged: Boolean = false) {
        if (tokenChanged) subscribed = false
        if (subscribed || !canShow(context) || !isAvailable(context)) return

        val messaging = FirebaseMessaging.getInstance()
        messaging.isAutoInitEnabled = true
        subscribed = true
        messaging.subscribeToTopic(TOPIC).addOnFailureListener {
            synchronized(this) { subscribed = false }
        }
    }

    fun show(context: Context, message: RemoteMessage) {
        val content = message.notification ?: return
        if (!canShow(context)) return
        createChannel(context)

        val intent = Intent(context, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        val openApp = PendingIntent.getActivity(
            context, 0, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val builder = if (Build.VERSION.SDK_INT >= 26) {
            val channelId = if (message.data["type"] in setOf("mcp_debt_changed", "sms_balance_low", "daily_debtor_summary")) PRIVATE_CHANNEL
                else context.getString(R.string.announcement_channel_id)
            Notification.Builder(context, channelId)
        } else {
            @Suppress("DEPRECATION")
            Notification.Builder(context).setDefaults(Notification.DEFAULT_ALL)
        }
        val notification = builder
            .setSmallIcon(R.drawable.ic_stat_announcement)
            .setContentTitle(content.title ?: context.getString(R.string.announcement_channel_name))
            .setContentText(content.body)
            .setStyle(Notification.BigTextStyle().bigText(content.body))
            .setContentIntent(openApp)
            .setAutoCancel(true)
            .setVisibility(Notification.VISIBILITY_PRIVATE)
            .build()

        try {
            manager(context).notify(message.messageId, 1, notification)
        } catch (_: SecurityException) {
            // The permission can be revoked after the initial check.
        }
    }

    fun getToken(context: Context, callback: (String?) -> Unit) {
        if (!isAvailable(context) || !canShow(context)) {
            callback(null)
            return
        }
        FirebaseMessaging.getInstance().token
            .addOnSuccessListener { callback(it) }
            .addOnFailureListener { callback(null) }
    }

    private fun createChannel(context: Context) {
        if (Build.VERSION.SDK_INT < 26) return
        val channel = NotificationChannel(
            context.getString(R.string.announcement_channel_id),
            context.getString(R.string.announcement_channel_name),
            NotificationManager.IMPORTANCE_DEFAULT
        )
        manager(context).createNotificationChannel(channel)
        manager(context).createNotificationChannel(NotificationChannel(
            PRIVATE_CHANNEL,
            "Oson POS hisob bildirishnomalari",
            NotificationManager.IMPORTANCE_DEFAULT
        ).apply { lockscreenVisibility = Notification.VISIBILITY_PRIVATE })
    }

    private fun isAvailable(context: Context): Boolean =
        Build.VERSION.SDK_INT >= 24 &&
            FirebaseApp.getApps(context).any { it.name == FirebaseApp.DEFAULT_APP_NAME } &&
            GoogleApiAvailabilityLight.getInstance().isGooglePlayServicesAvailable(context) == ConnectionResult.SUCCESS

    private fun canShow(context: Context): Boolean =
        Build.VERSION.SDK_INT >= 24 &&
            (Build.VERSION.SDK_INT < 33 ||
                context.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED) &&
            manager(context).areNotificationsEnabled()

    private fun manager(context: Context): NotificationManager =
        context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
}
