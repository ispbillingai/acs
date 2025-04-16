
import { useState, useEffect } from "react";
import { Card } from "@/components/ui/card";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { LaptopIcon, SmartphoneIcon, ServerIcon, WifiIcon } from "lucide-react";

interface Client {
  id: string;
  hostname: string;
  ipAddress: string;
  macAddress: string;
  isActive: boolean;
  lastSeen: string;
  connectionType: "wifi" | "lan";
  deviceType: "mobile" | "computer" | "unknown";
}

interface ConnectedClientsTableProps {
  deviceId: string;
}

export const ConnectedClientsTable = ({ deviceId }: ConnectedClientsTableProps) => {
  const [clients, setClients] = useState<Client[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchClients = async () => {
      setLoading(true);
      
      try {
        // In a real application, this would be an API call
        // For this demo, we'll use mock data
        setTimeout(() => {
          const mockClients: Client[] = [
            {
              id: "1",
              hostname: "Galaxy-S20-Ultra",
              ipAddress: "192.168.100.2",
              macAddress: "AA:BB:CC:DD:EE:FF",
              isActive: true,
              lastSeen: new Date().toISOString(),
              connectionType: "wifi",
              deviceType: "mobile"
            },
            {
              id: "2",
              hostname: "Gomez",
              ipAddress: "192.168.100.3",
              macAddress: "AA:BB:CC:DD:EE:FF",
              isActive: true,
              lastSeen: new Date().toISOString(),
              connectionType: "lan",
              deviceType: "computer"
            },
            {
              id: "3",
              hostname: "faith-s-A04s",
              ipAddress: "192.168.100.4",
              macAddress: "AA:BB:CC:DD:EE:FF",
              isActive: true,
              lastSeen: new Date().toISOString(),
              connectionType: "wifi",
              deviceType: "mobile"
            },
            {
              id: "4",
              hostname: "Unknown Device",
              ipAddress: "192.168.100.23",
              macAddress: "AA:BB:CC:DD:EE:FF",
              isActive: true,
              lastSeen: new Date().toISOString(),
              connectionType: "lan",
              deviceType: "unknown"
            }
          ];
          
          setClients(mockClients);
          setLoading(false);
        }, 1000);
      } catch (err) {
        console.error("Error fetching clients:", err);
        setLoading(false);
      }
    };

    if (deviceId) {
      fetchClients();
    }
  }, [deviceId]);

  const getDeviceIcon = (client: Client) => {
    if (client.deviceType === "mobile") {
      return <SmartphoneIcon className="h-4 w-4 text-blue-500" />;
    }
    if (client.deviceType === "computer") {
      return <LaptopIcon className="h-4 w-4 text-green-500" />;
    }
    return <ServerIcon className="h-4 w-4 text-gray-500" />;
  };

  const getConnectionIcon = (client: Client) => {
    if (client.connectionType === "wifi") {
      return <WifiIcon className="h-4 w-4 text-purple-500 ml-1" />;
    }
    return null;
  };

  return (
    <Card className="p-6">
      <h2 className="text-xl font-semibold mb-4">Connected Clients</h2>
      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-[200px]">Device</TableHead>
              <TableHead>IP Address</TableHead>
              <TableHead>Connection</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right">Last Seen</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {loading ? (
              <TableRow>
                <TableCell colSpan={5} className="text-center py-8">
                  <div className="flex justify-center">
                    <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-500"></div>
                    <span className="ml-3">Loading clients...</span>
                  </div>
                </TableCell>
              </TableRow>
            ) : clients.length === 0 ? (
              <TableRow>
                <TableCell colSpan={5} className="text-center py-8">
                  No connected clients found
                </TableCell>
              </TableRow>
            ) : (
              clients.map((client) => (
                <TableRow key={client.id}>
                  <TableCell className="font-medium flex items-center">
                    {getDeviceIcon(client)}
                    <span className="ml-2">{client.hostname}</span>
                  </TableCell>
                  <TableCell>{client.ipAddress}</TableCell>
                  <TableCell>
                    <div className="flex items-center">
                      <Badge className={client.connectionType === "wifi" ? "bg-purple-500" : "bg-blue-500"}>
                        {client.connectionType.toUpperCase()}
                      </Badge>
                      {getConnectionIcon(client)}
                    </div>
                  </TableCell>
                  <TableCell>
                    <Badge variant={client.isActive ? "default" : "outline"} className={client.isActive ? "bg-green-500" : ""}>
                      {client.isActive ? "Active" : "Inactive"}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right text-sm text-gray-500">
                    {new Date(client.lastSeen).toLocaleString()}
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
    </Card>
  );
};
