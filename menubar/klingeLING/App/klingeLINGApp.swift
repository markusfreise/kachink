import SwiftUI

@main
struct klingeLINGApp: App {
    @State private var appState = AppState()

    var body: some Scene {
        MenuBarExtra {
            PopoverContentView()
                .environment(appState)
                .frame(width: 340)
        } label: {
            MenuBarLabel(state: appState.menuBarState, elapsed: appState.timerVM.menuBarElapsed)
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
        } else if appState.isRestoringSession {
            ProgressView("Connecting...")
                .padding(40)
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
            Image(systemName: "clock")
        case .tracking:
            HStack(spacing: 4) {
                Image(systemName: "clock.fill")
                Text(elapsed).monospacedDigit()
            }
        case .error:
            Image(systemName: "clock.badge.exclamationmark")
        }
    }
}
