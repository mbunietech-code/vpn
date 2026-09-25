allprojects {
    repositories {
        google()
        mavenCentral()
    }
}

val newBuildDir: Directory =
    rootProject.layout.buildDirectory
        .dir("../../build")
        .get()
rootProject.layout.buildDirectory.value(newBuildDir)

subprojects {
    val newSubprojectBuildDir: Directory = newBuildDir.dir(project.name)
    project.layout.buildDirectory.value(newSubprojectBuildDir)
}
subprojects {
    project.evaluationDependsOn(":app")
}

// Some pub plugins (e.g. file_picker 8.3.7) hard-code an old compileSdk 34,
// but flutter_plugin_android_lifecycle now requires every consumer to compile
// against SDK 36. Force it on every Android subproject.
subprojects {
    afterEvaluate {
        val androidExt = extensions.findByName("android")
                as? com.android.build.gradle.BaseExtension
        if (androidExt != null) {
            androidExt.compileSdkVersion(36)
        }
    }
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}
