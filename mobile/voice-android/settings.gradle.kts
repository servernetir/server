pluginManagement {
    repositories { google(); mavenCentral(); gradlePluginPortal() }
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
        maven("https://download.linphone.org/maven_repository") {
            content { includeGroup("org.linphone.no-video") }
        }
    }
}
rootProject.name = "ServerNetVoice"
include(":app")
