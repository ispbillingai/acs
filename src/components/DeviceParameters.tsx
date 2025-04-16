
import { useState, useEffect } from "react";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { InfoIcon, WifiIcon, KeyIcon, SignalIcon, LockIcon, ShieldIcon, UserIcon, GlobeIcon, ServerIcon, AlertCircleIcon } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { useToast } from "@/hooks/use-toast";

interface DeviceParametersProps {
  deviceId: string;
}

interface Parameter {
  name: string;
  value: string;
  type: string;
  writable: boolean;
  category?: string;
  network_type?: string;
}

interface RouterSSIDsResponse {
  timestamp: string;
  ssids: Array<{parameter: string, value: string, network_type?: string}>;
  passwords: Array<{parameter: string, value: string, network_type?: string}>;
  raw_parameters: Array<{name: string, value: string}>;
  password_protected: boolean;
  lan_users?: Array<{mac: string, ip: string, hostname?: string}>;
  wifi_users?: Array<{mac: string, signal?: string, connected_to?: string}>;
  wan_settings?: Array<{name: string, value: string}>;
  errors?: Array<{type: string, message?: string, parameter?: string, value?: string}>;
  device_info?: {
    model?: string;
    serial?: string;
    type?: string;
    connected_hosts?: number;
  };
  mikrotik_errors?: number;
  huawei_errors?: number;
  discovery_status?: string;
}

export const DeviceParameters = ({ deviceId }: DeviceParametersProps) => {
  const [searchTerm, setSearchTerm] = useState("");
  const [parameters, setParameters] = useState<Parameter[]>([]);
  const [loading, setLoading] = useState(true);
  const [tr069Data, setTr069Data] = useState<RouterSSIDsResponse | null>(null);
  const [showPasswords, setShowPasswords] = useState(false);
  const { toast } = useToast();

  useEffect(() => {
    // This function fetches both mock data and checks for router SSIDs
    const fetchParameters = async () => {
      setLoading(true);
      
      try {
        // Start with mock data
        const mockData: Parameter[] = [
          {
            name: "InternetGatewayDevice.DeviceInfo.SoftwareVersion",
            value: "V1.2.3",
            type: "string",
            writable: false,
          },
          {
            name: "InternetGatewayDevice.DeviceInfo.UpTime",
            value: "12345",
            type: "unsignedInt",
            writable: false,
          },
          {
            name: "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress",
            value: "192.168.1.100",
            type: "string",
            writable: false,
          },
        ];

        // Try to fetch real SSID data from the server
        try {
          const response = await fetch("/api/router_ssids.php");
          if (response.ok) {
            const data: RouterSSIDsResponse = await response.json();
            setTr069Data(data);
            
            // Show device information if available
            if (data.device_info) {
              const deviceInfo = data.device_info;
              if (deviceInfo.model || deviceInfo.type) {
                const deviceType = deviceInfo.type || 'Unknown';
                const model = deviceInfo.model || 'Unknown Model';
                toast({
                  title: `${deviceType} Router Detected`,
                  description: `Model: ${model}${deviceInfo.serial ? ', Serial: ' + deviceInfo.serial : ''}`,
                  duration: 5000,
                });
                
                // Add device info to parameters
                if (deviceInfo.model) {
                  mockData.push({
                    name: "Device.ModelName",
                    value: deviceInfo.model,
                    type: "string",
                    writable: false,
                    category: "device_info"
                  });
                }
                
                if (deviceInfo.serial) {
                  mockData.push({
                    name: "Device.SerialNumber",
                    value: deviceInfo.serial,
                    type: "string",
                    writable: false,
                    category: "device_info"
                  });
                }
                
                if (deviceInfo.connected_hosts) {
                  mockData.push({
                    name: "Device.ConnectedHosts",
                    value: deviceInfo.connected_hosts.toString(),
                    type: "unsignedInt",
                    writable: false,
                    category: "device_info"
                  });
                }
              }
            }
            
            // Show error information if available
            if (data.mikrotik_errors && data.mikrotik_errors > 0) {
              toast({
                title: "MikroTik Errors Detected",
                description: `Detected ${data.mikrotik_errors} MikroTik errors. These devices don't fully support TR-069 parameter discovery.`,
                variant: "destructive",
                duration: 5000,
              });
            }
            
            if (data.huawei_errors && data.huawei_errors > 0) {
              toast({
                title: "Huawei Device Errors",
                description: `Encountered ${data.huawei_errors} errors during parameter discovery.`,
                variant: "default",
                duration: 5000,
              });
            }
            
            // Add SSIDs from the response
            if (data.ssids && data.ssids.length > 0) {
              data.ssids.forEach(ssid => {
                mockData.push({
                  name: ssid.parameter,
                  value: ssid.value,
                  type: "string",
                  writable: false,
                  category: "ssid",
                  network_type: ssid.network_type
                });
              });
              
              // Notify user about retrieved SSIDs
              toast({
                title: "WiFi Information Retrieved",
                description: `Successfully retrieved ${data.ssids.length} SSID(s) from the router.`,
                duration: 5000,
              });
            }
            
            // Add passwords from the response
            if (data.passwords && data.passwords.length > 0) {
              data.passwords.forEach(password => {
                mockData.push({
                  name: password.parameter,
                  value: password.value,
                  type: "string",
                  writable: false,
                  category: "password",
                  network_type: password.network_type
                });
              });
            }
            
            // Add WAN settings if available
            if (data.wan_settings && data.wan_settings.length > 0) {
              data.wan_settings.forEach(setting => {
                mockData.push({
                  name: setting.name,
                  value: setting.value,
                  type: "string",
                  writable: false,
                  category: "wan"
                });
              });
              
              toast({
                title: "WAN Settings Retrieved",
                description: `Retrieved ${data.wan_settings.length} WAN configuration parameters.`,
                duration: 5000,
              });
            }
            
            // Add LAN/WiFi users if available
            if (data.lan_users && data.lan_users.length > 0) {
              data.lan_users.forEach((user, index) => {
                mockData.push({
                  name: `LAN.ConnectedDevice.${index+1}.MACAddress`,
                  value: user.mac,
                  type: "string",
                  writable: false,
                  category: "lan_user"
                });
                mockData.push({
                  name: `LAN.ConnectedDevice.${index+1}.IPAddress`,
                  value: user.ip,
                  type: "string",
                  writable: false,
                  category: "lan_user"
                });
                if (user.hostname) {
                  mockData.push({
                    name: `LAN.ConnectedDevice.${index+1}.HostName`,
                    value: user.hostname,
                    type: "string",
                    writable: false,
                    category: "lan_user"
                  });
                }
              });
              
              toast({
                title: "LAN Users Retrieved",
                description: `Found ${data.lan_users.length} devices connected via LAN.`,
                duration: 5000,
              });
            }
            
            if (data.wifi_users && data.wifi_users.length > 0) {
              data.wifi_users.forEach((user, index) => {
                mockData.push({
                  name: `WiFi.ConnectedDevice.${index+1}.MACAddress`,
                  value: user.mac,
                  type: "string",
                  writable: false,
                  category: "wifi_user"
                });
                if (user.signal) {
                  mockData.push({
                    name: `WiFi.ConnectedDevice.${index+1}.SignalStrength`,
                    value: user.signal,
                    type: "string",
                    writable: false,
                    category: "wifi_user"
                  });
                }
                if (user.connected_to) {
                  mockData.push({
                    name: `WiFi.ConnectedDevice.${index+1}.ConnectedTo`,
                    value: user.connected_to,
                    type: "string",
                    writable: false,
                    category: "wifi_user"
                  });
                }
              });
              
              toast({
                title: "WiFi Users Retrieved",
                description: `Found ${data.wifi_users.length} devices connected via WiFi.`,
                duration: 5000,
              });
            }
            
            // Add any other parameters
            if (data.raw_parameters && data.raw_parameters.length > 0) {
              data.raw_parameters.forEach(param => {
                // Only add if not already added as ssid or password
                const isAlreadyAdded = mockData.some(p => p.name === param.name);
                if (!isAlreadyAdded) {
                  mockData.push({
                    name: param.name,
                    value: param.value,
                    type: "string",
                    writable: false,
                    category: "other"
                  });
                }
              });
            }
            
            // Show warning if passwords are protected
            if (data.password_protected) {
              toast({
                title: "Password Protection Active",
                description: "Your router is configured to hide WiFi passwords from TR-069 requests. This is a security feature.",
                variant: "destructive",
                duration: 8000,
              });
            }
            
            // Show discovery status if available
            if (data.discovery_status === 'completed') {
              toast({
                title: "Network Discovery Completed",
                description: "Router parameter discovery process has completed.",
                duration: 5000,
              });
            }
          }
        } catch (error) {
          console.error("Error fetching router SSIDs:", error);
          toast({
            title: "Connection Error",
            description: "Could not retrieve WiFi information from the router.",
            variant: "destructive",
            duration: 5000,
          });
        }
        
        setParameters(mockData);
      } finally {
        setLoading(false);
      }
    };

    fetchParameters();
  }, [deviceId, toast]);

  const filteredParameters = parameters.filter((param) =>
    param.name.toLowerCase().includes(searchTerm.toLowerCase()) || 
    param.value.toLowerCase().includes(searchTerm.toLowerCase())
  );

  // Check if we have any SSID parameters
  const hasSSIDs = parameters.some(param => param.category === 'ssid');
  const hasPasswords = parameters.some(param => param.category === 'password');
  const hasLanUsers = parameters.some(param => param.category === 'lan_user');
  const hasWifiUsers = parameters.some(param => param.category === 'wifi_user');
  const hasWanSettings = parameters.some(param => param.category === 'wan');
  const hasDeviceInfo = parameters.some(param => param.category === 'device_info');
  const passwordsProtected = tr069Data?.password_protected || false;

  const getCategoryIcon = (param: Parameter) => {
    if (param.category === 'ssid') return <WifiIcon className="h-4 w-4 text-blue-500" />;
    if (param.category === 'password') return <KeyIcon className="h-4 w-4 text-amber-500" />;
    if (param.category === 'lan_user') return <UserIcon className="h-4 w-4 text-green-500" />;
    if (param.category === 'wifi_user') return <SignalIcon className="h-4 w-4 text-purple-500" />;
    if (param.category === 'wan') return <GlobeIcon className="h-4 w-4 text-indigo-500" />;
    if (param.category === 'device_info') return <ServerIcon className="h-4 w-4 text-gray-500" />;
    return null;
  };

  const getCategoryBadge = (param: Parameter) => {
    if (param.category === 'ssid') {
      return (
        <div className="flex items-center gap-1">
          <Badge className="bg-blue-500">SSID</Badge>
          {param.network_type && (
            <Badge className={param.network_type === '5GHz' ? 'bg-purple-500' : 'bg-green-500'}>
              {param.network_type}
            </Badge>
          )}
        </div>
      );
    }
    if (param.category === 'password') {
      return (
        <div className="flex items-center gap-1">
          <Badge className="bg-amber-500">Password</Badge>
          {param.network_type && (
            <Badge className={param.network_type === '5GHz' ? 'bg-purple-500' : 'bg-green-500'}>
              {param.network_type}
            </Badge>
          )}
        </div>
      );
    }
    if (param.category === 'lan_user') {
      return <Badge className="bg-green-500">LAN User</Badge>;
    }
    if (param.category === 'wifi_user') {
      return <Badge className="bg-purple-500">WiFi User</Badge>;
    }
    if (param.category === 'wan') {
      return <Badge className="bg-indigo-500">WAN Setting</Badge>;
    }
    if (param.category === 'device_info') {
      return <Badge className="bg-gray-500">Device Info</Badge>;
    }
    return null;
  };

  // Function to display password value
  const displayValue = (param: Parameter) => {
    if (param.category === 'password' && !showPasswords) {
      return "••••••••••••••";
    }
    return param.value;
  };

  return (
    <Card className="p-6">
      <div className="mb-6 flex flex-wrap gap-4 justify-between items-center">
        <Input
          placeholder="Search parameters..."
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
          className="max-w-sm"
        />
        
        {hasPasswords && (
          <button 
            onClick={() => setShowPasswords(!showPasswords)}
            className={`px-4 py-2 rounded-md font-medium flex items-center gap-2 ${
              showPasswords ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700'
            }`}
          >
            <KeyIcon size={16} />
            {showPasswords ? 'Hide Passwords' : 'Show Passwords'}
          </button>
        )}
      </div>

      {!loading && tr069Data?.device_info && (tr069Data.device_info.model || tr069Data.device_info.type) && (
        <Alert className="mb-6 bg-gray-50 border-gray-200">
          <ServerIcon className="h-4 w-4 text-gray-700" />
          <AlertTitle className="text-gray-800">Router Information</AlertTitle>
          <AlertDescription className="text-gray-700">
            {tr069Data.device_info.type && <span className="font-medium">Type: {tr069Data.device_info.type}</span>}
            {tr069Data.device_info.model && <span className="block mt-1">Model: {tr069Data.device_info.model}</span>}
            {tr069Data.device_info.serial && <span className="block mt-1">Serial: {tr069Data.device_info.serial}</span>}
            {tr069Data.device_info.connected_hosts && <span className="block mt-1">Connected Hosts: {tr069Data.device_info.connected_hosts}</span>}
            
            {/* Error counts if available */}
            {(tr069Data.mikrotik_errors && tr069Data.mikrotik_errors > 0) && (
              <span className="block mt-2 text-amber-600 flex items-center">
                <AlertCircleIcon className="h-4 w-4 mr-1" />
                MikroTik Errors: {tr069Data.mikrotik_errors} (These devices often don't support parameter discovery)
              </span>
            )}
            
            {(tr069Data.huawei_errors && tr069Data.huawei_errors > 0) && (
              <span className="block mt-1 text-amber-600">Huawei Errors: {tr069Data.huawei_errors}</span>
            )}
            
            {tr069Data.discovery_status === 'completed' && (
              <span className="block mt-2 text-green-600">Network discovery completed successfully</span>
            )}
          </AlertDescription>
        </Alert>
      )}

      {!loading && hasSSIDs && (
        <Alert className="mb-6 bg-green-50 border-green-200">
          <InfoIcon className="h-4 w-4 text-green-500" />
          <AlertTitle className="text-green-700">WiFi Information Retrieved!</AlertTitle>
          <AlertDescription className="text-green-600">
            Successfully retrieved {tr069Data?.ssids.length || 0} SSID(s) from the router.
            {hasLanUsers && (
              <span className="block mt-1">Found {tr069Data?.lan_users?.length || 0} devices connected via LAN.</span>
            )}
            {hasWifiUsers && (
              <span className="block mt-1">Found {tr069Data?.wifi_users?.length || 0} devices connected via WiFi.</span>
            )}
            {hasWanSettings && (
              <span className="block mt-1">Retrieved WAN configuration settings.</span>
            )}
            {passwordsProtected && (
              <span className="block mt-1 flex items-center">
                <ShieldIcon className="h-4 w-4 mr-1 text-amber-500" />
                Your router is configured to protect WiFi passwords from remote access.
              </span>
            )}
          </AlertDescription>
        </Alert>
      )}

      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-[50%]">Parameter</TableHead>
              <TableHead>Value</TableHead>
              <TableHead>Type</TableHead>
              <TableHead className="w-[100px]">Category</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {loading ? (
              <TableRow>
                <TableCell colSpan={4} className="text-center py-4">
                  Loading parameters...
                </TableCell>
              </TableRow>
            ) : filteredParameters.length === 0 ? (
              <TableRow>
                <TableCell colSpan={4} className="text-center py-4">
                  No parameters found
                </TableCell>
              </TableRow>
            ) : (
              filteredParameters.map((param) => (
                <TableRow 
                  key={param.name} 
                  className={
                    param.category === 'ssid' 
                      ? "bg-blue-50" 
                      : param.category === 'password' 
                        ? "bg-amber-50" 
                        : param.category === 'lan_user'
                          ? "bg-green-50"
                          : param.category === 'wifi_user'
                            ? "bg-purple-50"
                            : param.category === 'wan'
                              ? "bg-indigo-50"
                              : param.category === 'device_info'
                                ? "bg-gray-50"
                                : ""
                  }
                >
                  <TableCell className="font-mono text-sm flex items-center">
                    {getCategoryIcon(param)}
                    <span className="ml-2">{param.name}</span>
                  </TableCell>
                  <TableCell className="font-medium">
                    {displayValue(param)}
                  </TableCell>
                  <TableCell>{param.type}</TableCell>
                  <TableCell>{getCategoryBadge(param)}</TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      {!loading && !hasSSIDs && (
        <Alert className="mt-6 bg-blue-50 border-blue-200">
          <InfoIcon className="h-4 w-4 text-blue-500" />
          <AlertTitle className="text-blue-700">No Router Information Yet</AlertTitle>
          <AlertDescription className="text-blue-600">
            No router information has been retrieved yet. 
            Connect a TR-069 enabled router to discover network details.
          </AlertDescription>
        </Alert>
      )}
    </Card>
  );
};
