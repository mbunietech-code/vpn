plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
    id("com.google.protobuf") version "0.9.4"
    id("com.squareup.wire") version "5.3.1"
}

wire {
    kotlin {
        android = true
    }
    protoPath {
        srcDir("src/main/protos")
    }
    sourcePath {
        srcDir("src/main/protos")
        include("**")
    }
}

android {
    namespace = "com.mbunie.mvpn"
    // Some plugins (flutter_plugin_android_lifecycle) require compileSdk 36+.
    compileSdk = maxOf(flutter.compileSdkVersion, 36)
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    buildFeatures {
        buildConfig = true
        aidl = true
    }

    defaultConfig {
        applicationId = "com.mbunie.mvpn"
        minSdk = maxOf(flutter.minSdkVersion, 24)
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
        multiDexEnabled = true
    }

    buildTypes {
        release {
            // TODO: Add your own signing config for the release build.
            // Signing with the debug keys for now, so `flutter run --release` works.
            signingConfig = signingConfigs.getByName("debug")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}

dependencies {
    implementation(fileTree(mapOf("dir" to "libs", "include" to listOf("*.jar", "*.aar"))))

    implementation("com.google.code.gson:gson:2.11.0")
    implementation("androidx.core:core-ktx:1.17.0")
    implementation("androidx.appcompat:appcompat:1.7.1")
    implementation("androidx.lifecycle:lifecycle-livedata-ktx:2.9.4")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.9.4")
    implementation("androidx.compose.ui:ui:1.9.3")
    implementation("com.squareup.wire:wire-grpc-client:5.3.1")

    implementation("io.grpc:grpc-okhttp:1.75.0")
    implementation("io.grpc:grpc-protobuf-lite:1.75.0")
    implementation("io.grpc:grpc-stub:1.75.0")
}
