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

// Some pub plugins (e.g. file_picker 8.3.7) hard-code an old compileSdk 34,
// but flutter_plugin_android_lifecycle now requires every consumer to compile
// against SDK 36. Force it on every Android subproject.
// Must be registered BEFORE evaluationDependsOn(":app") below, which forces
// projects to evaluate early; afterEvaluate on an evaluated project throws.
subprojects {
    val forceCompileSdk: Project.() -> Unit = {
        (extensions.findByName("android") as? com.android.build.gradle.BaseExtension)
            ?.compileSdkVersion(36)
    }
    if (state.executed) forceCompileSdk() else afterEvaluate { forceCompileSdk() }
}

subprojects {
    project.evaluationDependsOn(":app")
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}
