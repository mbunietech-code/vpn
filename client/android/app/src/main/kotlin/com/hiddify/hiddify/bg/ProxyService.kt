package com.hiddify.hiddify.bg

import android.app.Service
import android.content.Intent
import io.nekohasekai.libbox.Notification

class ProxyService :
    Service(),
    PlatformInterfaceWrapper {
    private val service = BoxService(this, this)

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int) = service.onStartCommand()

    override fun onBind(intent: Intent) = service.onBind(intent)

    override fun onDestroy() = service.onDestroy()

    override fun sendNotification(notification: Notification?) {
        if (notification != null) service.sendNotification(notification)
    }
}
