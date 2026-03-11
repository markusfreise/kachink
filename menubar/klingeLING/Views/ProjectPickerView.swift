import SwiftUI

struct ProjectPickerView: View {
    let projects: [ProjectDTO]
    @Binding var selection: ProjectDTO?
    @State private var search = ""
    @Environment(\.dismiss) private var dismiss

    private var filtered: [ProjectDTO] {
        if search.isEmpty { return projects }
        let q = search.lowercased()
        return projects.filter {
            $0.name.lowercased().contains(q) ||
            ($0.client?.name.lowercased().contains(q) ?? false)
        }
    }

    private var grouped: [(String, [ProjectDTO])] {
        let dict = Dictionary(grouping: filtered) { $0.client?.name ?? "No Client" }
        return dict.sorted { $0.key < $1.key }
    }

    var body: some View {
        VStack(spacing: 0) {
            TextField("Search projects...", text: $search)
                .textFieldStyle(.roundedBorder)
                .padding(8)

            List {
                ForEach(grouped, id: \.0) { clientName, clientProjects in
                    Section(clientName) {
                        ForEach(clientProjects) { project in
                            Button {
                                selection = project
                                dismiss()
                            } label: {
                                HStack {
                                    Circle()
                                        .fill(Color(hex: project.color))
                                        .frame(width: 8, height: 8)
                                    Text(project.name)
                                        .font(.caption)
                                    Spacer()
                                    if selection?.id == project.id {
                                        Image(systemName: "checkmark")
                                            .font(.caption)
                                            .foregroundStyle(.blue)
                                    }
                                }
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
            }
            .listStyle(.plain)
        }
    }
}
