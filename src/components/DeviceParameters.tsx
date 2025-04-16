
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
import { InfoIcon, WifiIcon, KeyIcon, SignalIcon, ExternalLinkIcon } from "lucide-react";
import { Badge } from "@/components/ui/badge";

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
}

export const DeviceParameters = ({ deviceId }: DeviceParametersProps) => {
  const [searchTerm, setSearchTerm] = useState("");
  const [parameters, setParameters] = useState<Parameter[]>([]);
  const [loading, setLoading] = useState(true);
  const [tr069Data, setTr069Data] = useState<RouterSSIDsResponse | null>(null);
  const [showPasswords, setShowPasswords] = useState(false);

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
          }
        } catch (error) {
          console.error("Error fetching router SSIDs:", error);
        }
        
        setParameters(mockData);
      } finally {
        setLoading(false);
      }
    };

    fetchParameters();
  }, [deviceId]);

  const filteredParameters = parameters.filter((param) =>
    param.name.toLowerCase().includes(searchTerm.toLowerCase()) || 
    param.value.toLowerCase().includes(searchTerm.toLowerCase())
  );

  // Check if we have any SSID parameters
  const hasSSIDs = parameters.some(param => param.category === 'ssid');
  const hasPasswords = parameters.some(param => param.category === 'password');

  const getCategoryIcon = (param: Parameter) => {
    if (param.category === 'ssid') return <WifiIcon className="h-4 w-4 text-blue-500" />;
    if (param.category === 'password') return <KeyIcon className="h-4 w-4 text-amber-500" />;
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

      {!loading && hasSSIDs && (
        <Alert className="mb-6 bg-green-50 border-green-200">
          <InfoIcon className="h-4 w-4 text-green-500" />
          <AlertTitle className="text-green-700">WiFi Information Retrieved!</AlertTitle>
          <AlertDescription className="text-green-600">
            Successfully retrieved {tr069Data?.ssids.length || 0} SSID(s) and {tr069Data?.passwords.length || 0} password(s) from the router.
            {hasPasswords && (
              <span className="block mt-1">
                {showPasswords ? 
                  "Passwords are currently visible. Click 'Hide Passwords' for security." : 
                  "For security, passwords are hidden. Click 'Show Passwords' to view them."}
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
                  className={param.category === 'ssid' 
                    ? "bg-blue-50" 
                    : param.category === 'password' 
                      ? "bg-amber-50" 
                      : ""}
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
          <AlertTitle className="text-blue-700">No WiFi Information Yet</AlertTitle>
          <AlertDescription className="text-blue-600">
            No WiFi SSIDs or passwords have been retrieved from a router yet. 
            Connect a TR-069 enabled router to discover WiFi credentials.
          </AlertDescription>
        </Alert>
      )}
    </Card>
  );
};
