# One-off: adds the PacketTunnel extension target to Runner.xcodeproj and
# gives Runner its entitlements. Already applied; kept to show how the
# project was set up. Run with CocoaPods' Ruby (it ships the xcodeproj gem):
#   GEM_HOME=$(brew --prefix cocoapods)/libexec \
#     /opt/homebrew/opt/ruby/bin/ruby ios/engine-scripts/add-tunnel-target.rb
# (or any Ruby with `gem install xcodeproj`), from client/.
require 'xcodeproj'

project_path = File.expand_path('../Runner.xcodeproj', __dir__)
project = Xcodeproj::Project.open(project_path)
abort 'PacketTunnel target already exists' if project.targets.any? { |t| t.name == 'PacketTunnel' }

runner = project.targets.find { |t| t.name == 'Runner' }
ext = project.new_target(:app_extension, 'PacketTunnel', :ios, '15.0')

group = project.main_group.new_group('PacketTunnel', 'PacketTunnel')
%w[PacketTunnelProvider.swift TunnelPlatformInterface.swift].each do |f|
  ext.source_build_phase.add_file_reference(group.new_file(f))
end
group.new_file('Info.plist')
group.new_file('PacketTunnel.entitlements')
xcconfig = group.new_file('PacketTunnel.xcconfig')

frameworks = project.main_group.find_subpath('Frameworks', true)
libbox = frameworks.new_file('Frameworks/Libbox.xcframework')
ext.frameworks_build_phase.add_file_reference(libbox)
%w[NetworkExtension.framework UIKit.framework libresolv.tbd].each do |lib|
  ref = project.frameworks_group.new_reference(
    lib.end_with?('.tbd') ? "usr/lib/#{lib}" : "System/Library/Frameworks/#{lib}"
  )
  ref.source_tree = 'SDKROOT'
  ext.frameworks_build_phase.add_file_reference(ref)
end

ext.build_configurations.each do |c|
  s = c.build_settings
  s['PRODUCT_BUNDLE_IDENTIFIER'] = 'com.mbunie.mvpn.tunnel'
  s['PRODUCT_NAME'] = '$(TARGET_NAME)'
  s['INFOPLIST_FILE'] = 'PacketTunnel/Info.plist'
  s['CODE_SIGN_ENTITLEMENTS'] = 'PacketTunnel/PacketTunnel.entitlements'
  s['IPHONEOS_DEPLOYMENT_TARGET'] = '15.0'
  s['SWIFT_VERSION'] = '5.0'
  s['TARGETED_DEVICE_FAMILY'] = '1,2'
  s['FRAMEWORK_SEARCH_PATHS'] = ['$(inherited)', '$(PROJECT_DIR)/Frameworks']
  s['OTHER_LDFLAGS'] = ['$(inherited)', '-lresolv']
  s['SKIP_INSTALL'] = 'YES'
  s['ENABLE_BITCODE'] = 'NO'
  s['CODE_SIGN_STYLE'] = 'Automatic'
  s['LD_RUNPATH_SEARCH_PATHS'] = ['$(inherited)', '@executable_path/Frameworks', '@executable_path/../../Frameworks']
end
# The extension's version follows the app's (pubspec: 1.0.24+25).
ext.build_configurations.each { |c| c.base_configuration_reference = xcconfig }
runner.build_configurations.each do |rc|
  rc.build_settings['CODE_SIGN_ENTITLEMENTS'] = 'Runner/Runner.entitlements'
end

# Embed the extension in the app, before Flutter's "Thin Binary" phase.
embed = runner.new_copy_files_build_phase('Embed Foundation Extensions')
embed.symbol_dst_subfolder_spec = :plug_ins
bf = embed.add_file_reference(ext.product_reference, true)
bf.settings = { 'ATTRIBUTES' => ['RemoveHeadersOnCopy'] }
thin = runner.build_phases.find { |p| p.respond_to?(:name) && p.name == 'Thin Binary' }
if thin
  runner.build_phases.delete(embed)
  runner.build_phases.insert(runner.build_phases.index(thin), embed)
end
runner.add_dependency(ext)

runner_group = project.main_group.find_subpath('Runner', false)
runner_group.new_file('Runner.entitlements')
runner.source_build_phase.add_file_reference(runner_group.new_file('VpnBridge.swift'))

project.save
puts 'PacketTunnel target added'
