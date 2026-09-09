import SwiftUI

/// Searchable in-place picker rendered inside the menu bar window
/// (nested popovers are unreliable in MenuBarExtra windows).
struct PickerListView<Item: Identifiable & Hashable>: View {
    let title: String
    let items: [Item]
    let allowNone: Bool
    let label: (Item) -> String
    let group: (Item) -> String?
    let color: (Item) -> String?
    let isSelected: (Item) -> Bool
    let onSelect: (Item?) -> Void
    let onCancel: () -> Void

    @State private var search = ""
    @FocusState private var searchFocused: Bool

    private var filtered: [Item] {
        let q = search.trimmingCharacters(in: .whitespaces).lowercased()
        guard !q.isEmpty else { return items }
        return items.filter { label($0).lowercased().contains(q) || (group($0)?.lowercased().contains(q) ?? false) }
    }

    private var grouped: [(String, [Item])] {
        let dict = Dictionary(grouping: filtered) { group($0) ?? "" }
        return dict.sorted { $0.key.localizedCaseInsensitiveCompare($1.key) == .orderedAscending }
    }

    var body: some View {
        VStack(spacing: 0) {
            HStack {
                Button { onCancel() } label: { Image(systemName: "chevron.left") }
                    .buttonStyle(.plain)
                Text(title).font(.headline)
                Spacer()
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)

            TextField("Search", text: $search)
                .textFieldStyle(.roundedBorder)
                .padding(.horizontal, 12)
                .padding(.bottom, 8)
                .focused($searchFocused)

            Divider()

            ScrollView {
                LazyVStack(alignment: .leading, spacing: 0) {
                    if allowNone && search.isEmpty {
                        row(title: "None", colorHex: nil, selected: false) { onSelect(nil) }
                    }
                    ForEach(grouped, id: \.0) { groupName, groupItems in
                        if !groupName.isEmpty {
                            Text(groupName)
                                .font(.caption2.weight(.semibold))
                                .foregroundStyle(.secondary)
                                .textCase(.uppercase)
                                .padding(.horizontal, 12)
                                .padding(.top, 8)
                                .padding(.bottom, 2)
                        }
                        ForEach(groupItems) { item in
                            row(title: label(item), colorHex: color(item), selected: isSelected(item)) { onSelect(item) }
                        }
                    }
                    if filtered.isEmpty {
                        Text("No results")
                            .font(.caption)
                            .foregroundStyle(.tertiary)
                            .padding(12)
                    }
                }
                .padding(.bottom, 8)
            }
            .frame(height: 320)
        }
        .onAppear { searchFocused = true }
    }

    private func row(title: String, colorHex: String?, selected: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 8) {
                if let colorHex {
                    Circle().fill(Color(hex: colorHex)).frame(width: 8, height: 8)
                }
                Text(title).lineLimit(1)
                Spacer()
                if selected {
                    Image(systemName: "checkmark").font(.caption).foregroundStyle(.tint)
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 6)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}
