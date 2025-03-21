
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
import { InfoIcon } from "lucide-react";

interface DeviceParametersProps {
  deviceId: string;
}

interface Parameter {
  name: string;
  value: string;
  type: string;
  writable: boolean;
}

export const DeviceParameters = ({ deviceId }: DeviceParametersProps) => {
  const [searchTerm, setSearchTerm] = useState("");
  const [parameters, setParameters] = useState<Parameter[]>([]);
  const [loading, setLoading] = useState(true);

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
            const ssidData = await response.text();
            
            // Parse the SSID data (format: paramName = paramValue)
            const ssidLines = ssidData.split("\n").filter(line => line.trim());
            
            for (const line of ssidLines) {
              const [name, value] = line.split(" = ");
              if (name && value) {
                mockData.push({
                  name: name.trim(),
                  value: value.trim(),
                  type: "string",
                  writable: false
                });
              }
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
    param.name.toLowerCase().includes(searchTerm.toLowerCase())
  );

  // Check if we have any SSID parameters
  const hasSSIDs = parameters.some(param => 
    param.name.includes("SSID") && param.name.endsWith(".SSID")
  );

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
        <Alert className="mb-6 bg-green-50">
          <InfoIcon className="h-4 w-4" />
          <AlertTitle>Success!</AlertTitle>
          <AlertDescription>
            Successfully retrieved SSID information from the router.
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
              <TableHead>Writable</TableHead>
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
                <TableRow key={param.name} className={param.name.includes("SSID") ? "bg-green-50" : ""}>
                  <TableCell className="font-mono text-sm">{param.name}</TableCell>
                  <TableCell>{param.value}</TableCell>
                  <TableCell>{param.type}</TableCell>
                  <TableCell>{param.writable ? "Yes" : "No"}</TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
    </Card>
  );
};
