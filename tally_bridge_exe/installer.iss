[Setup]
AppName=eBAL Smart Bridge
AppVersion=2.0.0
DefaultDirName={pf}\eBAL Smart Bridge
DefaultGroupName=eBAL Smart Bridge
OutputDir=dist
OutputBaseFilename=ebal-smart-bridge-setup
Compression=lzma
SolidCompression=yes
PrivilegesRequired=lowest
UninstallDisplayName=eBAL Smart Bridge

[Files]
Source: "dist\ebal_smart_bridge.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "config.json"; DestDir: "{app}"; Flags: ignoreversion onlyifdoesntexist
Source: "README.md"; DestDir: "{app}"; Flags: ignoreversion

[Dirs]
Name: "{localappdata}\eBAL Smart Bridge"; Flags: uninsalwaysuninstall

[Icons]
Name: "{group}\eBAL Smart Bridge"; Filename: "{app}\ebal_smart_bridge.exe"
Name: "{group}\Uninstall eBAL Smart Bridge"; Filename: "{uninstallexe}"
Name: "{commondesktop}\eBAL Smart Bridge"; Filename: "{app}\ebal_smart_bridge.exe"; Tasks: desktopicon

[Tasks]
Name: "desktopicon"; Description: "Create a desktop icon"; GroupDescription: "Additional icons:"
Name: "autostart"; Description: "Start with Windows"; GroupDescription: "Startup:"; Flags: checkedonce

[Registry]
; Auto-start with Windows (HKCU)
Root: HKA; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "eBAL Smart Bridge"; ValueData: """{app}\ebal_smart_bridge.exe"" /minimized"; Flags: uninsdeletevalue; Tasks: autostart

; Custom protocol handler ebalbridge://
Root: HKA; Subkey: "Software\Classes\ebalbridge"; ValueType: string; ValueName: ""; ValueData: "URL:eBAL Smart Bridge Protocol"; Flags: uninsdeletekey
Root: HKA; Subkey: "Software\Classes\ebalbridge"; ValueType: string; ValueName: "URL Protocol"; ValueData: ""; Flags: uninsdeletekey
Root: HKA; Subkey: "Software\Classes\ebalbridge\DefaultIcon"; ValueType: string; ValueName: ""; ValueData: "{app}\ebal_smart_bridge.exe,0"
Root: HKA; Subkey: "Software\Classes\ebalbridge\shell\open\command"; ValueType: string; ValueName: ""; ValueData: """{app}\ebal_smart_bridge.exe"" ""%1"""

[Run]
Filename: "{app}\ebal_smart_bridge.exe"; Parameters: "/minimized"; Description: "Start eBAL Smart Bridge in background"; Flags: nowait postinstall skipifsilent

[UninstallDelete]
Type: filesandordirs; Name: "{localappdata}\eBAL Smart Bridge"
