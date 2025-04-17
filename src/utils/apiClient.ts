
/**
 * API Client for interacting with the TR069 REST API
 */

// Base API URL
const BASE_URL = '/backend/api/rest';

interface ApiResponse<T> {
  success: boolean;
  error?: string;
  details?: string;
  [key: string]: any;
}

/**
 * Make a GET request to the API
 * @param endpoint - API endpoint to call
 * @param params - Query parameters
 * @returns Promise with response data
 */
export async function apiGet<T>(endpoint: string, params: Record<string, string> = {}): Promise<ApiResponse<T>> {
  // Build query string from params
  const queryParams = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    queryParams.append(key, value);
  });
  
  const queryString = queryParams.toString() ? `?${queryParams.toString()}` : '';
  const url = `${BASE_URL}/${endpoint}${queryString}`;
  
  const response = await fetch(url, {
    method: 'GET',
    headers: {
      'Accept': 'application/json',
    }
  });
  
  if (!response.ok) {
    throw new Error(`API error: ${response.status} ${response.statusText}`);
  }
  
  return await response.json();
}

/**
 * Make a POST request to the API
 * @param endpoint - API endpoint to call
 * @param data - JSON data to send
 * @returns Promise with response data
 */
export async function apiPost<T>(endpoint: string, data: any): Promise<ApiResponse<T>> {
  const url = `${BASE_URL}/${endpoint}`;
  
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify(data)
  });
  
  if (!response.ok) {
    throw new Error(`API error: ${response.status} ${response.statusText}`);
  }
  
  return await response.json();
}

// Specific API functions for devices

/**
 * Get a list of all devices
 */
export async function getDevices(page: number = 1, limit: number = 20) {
  return apiGet('devices.php', { page: page.toString(), limit: limit.toString() });
}

/**
 * Get a specific device by ID
 */
export async function getDeviceById(deviceId: string) {
  return apiGet('devices.php', { id: deviceId });
}

/**
 * Get a specific device by serial number
 */
export async function getDeviceBySerial(serialNumber: string) {
  return apiGet('devices.php', { serial: serialNumber });
}

/**
 * Update a device's information
 */
export async function updateDevice(deviceData: any) {
  return apiPost('devices.php', deviceData);
}

// Functions for parameters

/**
 * Get all parameters for a device
 */
export async function getDeviceParameters(deviceId: string) {
  return apiGet('parameters.php', { device_id: deviceId });
}

/**
 * Get a specific parameter for a device
 */
export async function getDeviceParameter(deviceId: string, paramName: string) {
  return apiGet('parameters.php', { device_id: deviceId, param: paramName });
}

/**
 * Set a parameter for a device
 */
export async function setDeviceParameter(deviceId: string, paramName: string, paramValue: string, paramType: string = 'string') {
  return apiPost('parameters.php', {
    action: 'set_parameter',
    device_id: deviceId,
    param_name: paramName,
    param_value: paramValue,
    param_type: paramType
  });
}

// Functions for device tasks

/**
 * Configure WiFi settings
 */
export async function configureWifi(deviceId: string, ssid: string, password: string) {
  return apiPost('parameters.php', {
    action: 'configure_wifi',
    device_id: deviceId,
    ssid,
    password
  });
}

/**
 * Configure WAN connection
 */
export async function configureWan(deviceId: string, connectionType: string, options: any = {}) {
  return apiPost('parameters.php', {
    action: 'configure_wan',
    device_id: deviceId,
    connection_type: connectionType,
    ...options
  });
}

/**
 * Reboot a device
 */
export async function rebootDevice(deviceId: string, reason: string = 'API initiated reboot') {
  return apiPost('parameters.php', {
    action: 'reboot',
    device_id: deviceId,
    reason
  });
}

/**
 * Get tasks for a device
 */
export async function getDeviceTasks(deviceId: string, status?: string) {
  const params: Record<string, string> = { device_id: deviceId };
  if (status) {
    params.status = status;
  }
  return apiGet('tasks.php', params);
}

/**
 * Get a specific task by ID
 */
export async function getTaskById(taskId: string) {
  return apiGet('tasks.php', { id: taskId });
}

/**
 * Cancel a task
 */
export async function cancelTask(taskId: string) {
  return apiPost('tasks.php', {
    task_id: taskId,
    action: 'cancel'
  });
}

/**
 * Retry a failed task
 */
export async function retryTask(taskId: string) {
  return apiPost('tasks.php', {
    task_id: taskId,
    action: 'retry'
  });
}
