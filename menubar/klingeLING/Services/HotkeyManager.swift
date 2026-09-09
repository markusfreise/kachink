import Carbon
import Foundation

/// Registers a global keyboard shortcut (Cmd+Shift+T) that toggles the timer.
final class HotkeyManager {
    nonisolated(unsafe) static var onToggle: (@MainActor () -> Void)?

    private var hotKeyRef: EventHotKeyRef?
    private var handlerRef: EventHandlerRef?

    func register() {
        var eventType = EventTypeSpec(eventClass: OSType(kEventClassKeyboard), eventKind: UInt32(kEventHotKeyPressed))

        InstallEventHandler(GetApplicationEventTarget(), { _, _, _ in
            if let handler = HotkeyManager.onToggle {
                Task { @MainActor in handler() }
            }
            return noErr
        }, 1, &eventType, nil, &handlerRef)

        let hotKeyID = EventHotKeyID(signature: OSType(0x4B43484B), id: 1) // "KCHK"
        RegisterEventHotKey(UInt32(kVK_ANSI_T), UInt32(cmdKey | shiftKey), hotKeyID, GetApplicationEventTarget(), 0, &hotKeyRef)
    }

    func unregister() {
        if let hotKeyRef { UnregisterEventHotKey(hotKeyRef) }
        if let handlerRef { RemoveEventHandler(handlerRef) }
        hotKeyRef = nil
        handlerRef = nil
    }
}
