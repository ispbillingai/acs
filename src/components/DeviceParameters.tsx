
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
import { InfoIcon, WifiIcon, KeyIcon, ExternalLinkIcon } from "lucide-react";
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
}

interface RouterSSIDsResponse {
  timestamp: string;
  ssids: Array<{parameter: string, value: string}>;
  passwords: Array<{parameter: string, value: string}>;
  raw_parameters: Array<{name: string, value: string}>;
}

export const DeviceParameters = ({ deviceId }: DeviceParametersProps) => {
  const [searchTerm, setSearchTerm] = useState("");
  const [parameters, setParameters] = useState<Parameter[]>([]);
  const [loading, setLoading] = useState(true);
  const [tr069Data, setTr069Data] = useState<RouterSSIDsResponse | null>(null);

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
                  category: "ssid"
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
                  category: "password"
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
    if (param.category === 'ssid') return <Badge className="bg-blue-500">SSID</Badge>;
    if (param.category === 'password') return <Badge className="bg-amber-500">Password</Badge>;
    return null;
  };

  return (
    <Card className="p-6">
      <div className="mb-6">
        <Input
          placeholder="Search parameters..."
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
          className="max-w-sm"
        />
      </div>

      {!loading && hasSSIDs && (
        <Alert className="mb-6 bg-green-50 border-green-200">
          <InfoIcon className="h-4 w-4 text-green-500" />
          <AlertTitle className="text-green-700">WiFi Information Retrieved!</AlertTitle>
          <AlertDescription className="text-green-600">
            Successfully retrieved {tr069Data?.ssids.length || 0} SSID(s) and {tr069Data?.passwords.length || 0} password(s) from the router.
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
                  <TableCell className="font-medium">{param.value}</TableCell>
                  <TableCell>{param.type}</TableCell>
                  <TableCell>{getCategoryBadge(param)}</TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
    </Card>
  );
};
