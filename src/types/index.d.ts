
export interface Device {
  id: string;
  status: string;
  manufacturer: string;
  model: string;
  serialNumber: string;
  softwareVersion?: string;
  hardwareVersion?: string;
  ipAddress: string;
  lastContact: string;
  connectedClients: number;
  uptime?: string;
  ssid?: string;
}

export interface DeviceParameter {
  id: string;
  paramName: string;
  paramValue: string;
  paramType: string;
  updatedAt: string;
}

export interface ConnectedClient {
  id: string;
  deviceId: string;
  ipAddress: string;
  hostname: string;
  macAddress: string;
  isActive: boolean;
  lastSeen: string;
}
