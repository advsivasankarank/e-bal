[Setup]
AppName=eBAL Smart Bridge
AppVersion=1.0.0
DefaultDirName={pf}\eBAL Smart Bridge
DefaultGroupName=eBAL Smart Bridge
OutputDir=dist
OutputBaseFilename=ebal-smart-bridge-setup
Compression=lzma
SolidCompression=yes

[Files]
Source: "dist\ebal_smart_bridge.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "config.json"; DestDir: "{app}"; Flags: ignoreversion onlyifdoesntexist
Source: "README.md"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{group}\eBAL Smart Bridge"; Filename: "{app}\ebal_smart_bridge.exe"
Name: "{commondesktop}\eBAL Smart Bridge"; Filename: "{app}\ebal_smart_bridge.exe"; Tasks: desktopicon

[Tasks]
Name: "desktopicon"; Description: "Create a desktop icon"; GroupDescription: "Additional icons:"

[Run]
Filename: "{app}\ebal_smart_bridge.exe"; Description: "Launch eBAL Smart Bridge"; Flags: nowait postinstall skipifsilent
