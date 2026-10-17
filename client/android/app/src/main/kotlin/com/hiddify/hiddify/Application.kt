package com.hiddify.hiddify

import android.app.Application
import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.ConnectivityManager
import android.net.wifi.WifiManager
import android.os.Build
import android.os.PowerManager
import androidx.core.content.getSystemService
import com.hiddify.hiddify.bg.AppChangeReceiver
import com.hiddify.hiddify.constant.Bugs
import com.mbunie.mvpn.BuildConfig
import io.nekohasekai.libbox.Libbox
import io.nekohasekai.libbox.SetupOptions
import java.io.File
import java.util.Locale
import com.hiddify.hiddify.Application as BoxApplication

class Application : Application() {

    override fun attachBaseContext(base: Context?) {
        super.attachBaseContext(base)
        application = this
    }

    override fun onCreate() {
        super.onCreate()

        runCatching {
            Libbox.setLocale(Locale.getDefault().toLanguageTag())
        }

        registerReceiver(AppChangeReceiver(), IntentFilter().apply {
            addAction(Intent.ACTION_PACKAGE_ADDED)
            addDataScheme("package")
        })
    }

    @Volatile
    private var libboxReady = false

    fun setupLibbox(baseDir: String, workingDir: String, tempDir: String, debug: Boolean) {
        Libbox.setup(createSetupOptions(File(baseDir), File(workingDir), File(tempDir), debug))
        libboxReady = true
    }

    /**
     * Android can start the VPN service without the Flutter UI (always-on VPN,
     * boot, the Quick Settings tile, or a restart after the process was
     * killed). Then the Flutter "setup" call never ran, libbox kept its "./"
     * defaults and the command server failed with "bind: read-only file
     * system". Set it up here from the saved dirs, or from app dirs.
     */
    @Synchronized
    fun ensureLibbox() {
        if (libboxReady) return
        fun saved(path: String) = path.takeUnless { it.isBlank() || it == "./" }
        val base = saved(Settings.baseDir) ?: filesDir.path
        val working = saved(Settings.workingDir)
            ?: File(getExternalFilesDir(null) ?: filesDir, "data").path
        val temp = saved(Settings.tempDir) ?: cacheDir.path
        File(working).mkdirs()
        setupLibbox(base, working, temp, Settings.debugMode)
    }

    private fun createSetupOptions(baseDir: File, workingDir: File, tempDir: File, debug: Boolean): SetupOptions =
        SetupOptions().also {
            it.basePath = baseDir.path
            it.workingPath = workingDir.path
            it.tempPath = tempDir.path
            it.fixAndroidStack = Bugs.fixAndroidStack
            it.commandServerListenPort = Settings.grpcServiceModePort
            it.commandServerSecret = ""
            it.logMaxLines = 3000
            it.debug = debug || BuildConfig.DEBUG
            it.crashReportSource = "MbunieVPN"
            it.appVersion = BuildConfig.VERSION_CODE.toString()
            it.appMarketingVersion = BuildConfig.VERSION_NAME
            it.oomKillerEnabled = false
            it.oomKillerDisabled = true
            it.oomMemoryLimit = 0
            it.powerReportEnabled = false
        }

    companion object {
        lateinit var application: BoxApplication
        val notification by lazy { application.getSystemService<NotificationManager>()!! }
        val connectivity by lazy { application.getSystemService<ConnectivityManager>()!! }
        val packageManager by lazy { application.packageManager }
        val powerManager by lazy { application.getSystemService<PowerManager>()!! }
        val notificationManager by lazy { application.getSystemService<NotificationManager>()!! }

        val wifiManager by lazy { application.getSystemService<WifiManager>()!! }

    }

}
