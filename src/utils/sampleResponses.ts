
export const deviceParametersSampleResponse = {
  // Successful response
  success: {
    success: true,
    device: {
      id: "11",
      serialNumber: "48575443F2D61173",
      manufacturer: "Huawei Technologies Co., Ltd",
      model: "HG8546M",
      status: "online",
      lastContact: "2025-04-17T14:45:23Z",
      ipAddress: "192.168.1.100",
      softwareVersion: "V1.2.3",
      hardwareVersion: "HW1.0",
      ssid: "HomeRouter",
      tr069Password: "******",
      localAdminPassword: "******"
    },
    parameters: [
      {
        name: "InternetGatewayDevice.DeviceInfo.SoftwareVersion",
        value: "V1.2.3",
        type: "string",
        updated_at: "2025-04-17T14:45:23Z"
      },
      {
        name: "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress",
        value: "203.0.113.45",
        type: "string",
        updated_at: "2025-04-17T14:40:53Z"
      },
      {
        name: "Device.WiFi.SSID.1.SSID",
        value: "HomeRouter_2.4G",
        type: "string",
        updated_at: "2025-04-17T14:38:12Z"
      },
      {
        name: "Device.WiFi.SSID.2.SSID",
        value: "HomeRouter_5G",
        type: "string",
        updated_at: "2025-04-17T14:38:12Z"
      },
      {
        name: "InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.IPInterface.1.IPAddress",
        value: "192.168.1.1",
        type: "string",
        updated_at: "2025-04-17T14:35:45Z"
      }
    ]
  },
  
  // Error response
  error: {
    success: false,
    error: "Device not found",
    details: "No device found with the specified ID or serial number",
    code: 404
  },

  // Partial parameters response (if only specific parameters are requested)
  partialSuccess: {
    success: true,
    parameter: {
      name: "InternetGatewayDevice.DeviceInfo.SoftwareVersion",
      value: "V1.2.3",
      type: "string",
      updated_at: "2025-04-17T14:45:23Z"
    }
  }
};

export const deviceParametersSampleRequests = {
  // Get all parameters for a device by ID
  getAllByDeviceId: {
    method: "GET",
    url: "/backend/api/rest/parameters.php?device_id=11"
  },
  
  // Get all parameters for a device by serial number
  getAllBySerial: {
    method: "GET", 
    url: "/backend/api/rest/parameters.php?serial=48575443F2D61173"
  },
  
  // Get a specific parameter
  getSpecificParameter: {
    method: "GET",
    url: "/backend/api/rest/parameters.php?device_id=11&param=InternetGatewayDevice.DeviceInfo.SoftwareVersion"
  },
  
  // Set a parameter
  setParameter: {
    method: "POST",
    url: "/backend/api/rest/parameters.php",
    body: {
      action: "set_parameter",
      device_id: "11",
      param_name: "Device.WiFi.SSID.1.SSID",
      param_value: "NewHomeRouter_2.4G",
      param_type: "string"
    }
  }
};
