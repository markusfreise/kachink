# Kachink menu bar app (macOS)

Swift/SwiftUI menu bar companion for the Kachink time tracker.

- Start, stop and switch timers; pick project and task; edit the description of the running entry
- Idle watchdog: after N minutes of inactivity (or sleep / screen lock) it asks whether to keep the idle time, discard it and continue, or stop the timer at the moment you went idle
- Global shortcut Cmd+Shift+T toggles the timer (restarts the last selection)
- Polls the server every 30 s so timers started in the web app show up
- Launch at login, organization switcher, seconds toggle in Settings

## Build

    brew install xcodegen
    cd menubar
    xcodegen generate
    xcodebuild -project klingeLING.xcodeproj -scheme klingeLING -configuration Release build

The built app is in `build/` (see xcodebuild output). Sign in with the server address, email and password of your Kachink account.
