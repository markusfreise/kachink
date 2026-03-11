import SwiftUI
import SwiftData

@main
struct klingeLINGApp: App {
    @State private var appState = AppState()

    var body: some Scene {
        MenuBarExtra {
            PopoverContentView()
                .environment(appState)
                .frame(width: 320, height: appState.isAuthenticated ? 480 : 260)
        } label: {
            MenuBarLabel(state: appState.menuBarState, elapsed: appState.timerVM.elapsedFormatted)
        }
        .menuBarExtraStyle(.window)

        Settings {
            SettingsView()
                .environment(appState)
        }
    }
}

struct PopoverContentView: View {
    @Environment(AppState.self) private var appState

    var body: some View {
        if appState.isAuthenticated {
            TimerPopoverView()
        } else {
            LoginView()
        }
    }
}

struct MenuBarLabel: View {
    let state: MenuBarState
    let elapsed: String

    var body: some View {
        switch state {
        case .idle:
            Label("klingeLING!", systemImage: "clock")
        case .tracking:
            Label(elapsed, systemImage: "clock.fill")
                .foregroundStyle(.green)
        case .offline:
            Label("Offline", systemImage: "clock.badge.xmark")
        }
    }
}
