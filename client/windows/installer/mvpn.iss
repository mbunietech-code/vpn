; Mbunie VPN — Windows installer (Inno Setup 6)
; Build: flutter build windows --release   (with windows/engine/sing-box.exe present)
;        ISCC.exe windows\installer\mvpn.iss

; VIP event app: ISCC.exe /DVip windows\installer\mvpn.iss (after the VIP build).
#ifdef Vip
  #define AppName "Mbunie VIP"
  #define AppGuid "{{B7A1D3E5-9C24-4F6A-8D17-3E5B9A2C7F10}"
  #define OutputName "Mbunie-VIP-Setup"
#else
  #define AppName "Mbunie VPN"
  #define AppGuid "{{6E0B7C2A-4F1D-4B8E-9C3A-2D5F8A1B7E44}"
  #define OutputName "Mbunie-VPN-Setup"
#endif
#define AppVersion "1.0.26"
#define AppExe "mvpn.exe"
#define BuildDir "..\..\build\windows\x64\runner\Release"

[Setup]
AppId={#AppGuid}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher=Mbunie Tech
DefaultDirName={autopf}\{#AppName}
DefaultGroupName={#AppName}
DisableProgramGroupPage=yes
; The app runs as Administrator (TUN adapter), so install machine-wide.
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir=..\..\..\apps
OutputBaseFilename={#OutputName}-{#AppVersion}
SetupIconFile=..\runner\resources\app_icon.ico
UninstallDisplayIcon={app}\{#AppExe}
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
CloseApplications=force

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"

[Files]
Source: "{#BuildDir}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{autoprograms}\{#AppName}"; Filename: "{app}\{#AppExe}"
Name: "{autodesktop}\{#AppName}"; Filename: "{app}\{#AppExe}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#AppExe}"; Description: "{cm:LaunchProgram,{#AppName}}"; Flags: nowait postinstall skipifsilent shellexec
; In-app update (silent install): start the new version again.
Filename: "{app}\{#AppExe}"; Parameters: "--after-update"; Flags: nowait; Check: WizardSilent

[UninstallRun]
; Make sure the tunnel engine is not left running.
Filename: "{sys}\taskkill.exe"; Parameters: "/F /IM sing-box.exe"; Flags: runhidden; RunOnceId: "KillEngine"
Filename: "{sys}\taskkill.exe"; Parameters: "/F /IM {#AppExe}"; Flags: runhidden; RunOnceId: "KillApp"
