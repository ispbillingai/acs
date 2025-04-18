
export interface Device {
  id: string;
  serialNumber: string;
  manufacturer?: string;
  model?: string;
  softwareVersion?: string;
  hardwareVersion?: string;
  status: 'online' | 'offline' | 'warning' | 'provisioning';
  lastContact: string;
  ipAddress?: string;
  ssid?: string;
  ssidPassword?: string;
  uptime?: string;
  connectedDevices?: string;
  txPower?: string;
  rxPower?: string;
  connectedHosts?: Client[];
  parameters?: Parameter[];
}

export interface Client {
  id: string;
  hostname: string;
  ipAddress: string;
  macAddress: string;
  isActive: boolean;
  lastSeen: string;
  connectionType: "wifi" | "lan";
  deviceType: "mobile" | "computer" | "unknown";
}

export interface Parameter {
  name: string;
  value: string;
  type: string;
  writable: boolean;
  category?: string;
  network_type?: string;
}
