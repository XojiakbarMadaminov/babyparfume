package com.babyparfume.babyparfume_mobile

import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

class AppFirebaseMessagingService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        AppPushNotifications.subscribe(this, tokenChanged = true)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        AppPushNotifications.show(this, message)
    }
}
