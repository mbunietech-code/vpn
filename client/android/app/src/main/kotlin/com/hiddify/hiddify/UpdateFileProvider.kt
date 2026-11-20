package com.hiddify.hiddify

import androidx.core.content.FileProvider

/** Shares a downloaded update APK with the package installer (own class so it never clashes with a plugin's provider). */
class UpdateFileProvider : FileProvider()
