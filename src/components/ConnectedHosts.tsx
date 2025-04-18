import { useState, useEffect } from "react";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { useToast } from "@/hooks/use-toast";
import { Laptop, RefreshCw, Server, SmartphoneCharging } from "lucide-react";

interface Host {
  id?: string;
  ipAddress: string;
  hostname: string;
  macAddress?: string;
  lastSeen?: string;
  isActive?: boolean;
}

interface ConnectedHostsProps {
  deviceId: string;
  refreshTrigger?: number;
}

export const ConnectedHosts = ({ deviceId, refreshTrigger }: ConnectedHostsProps) => {
  const [hosts, setHosts] = useState<Host[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [hostCount, setHostCount] = useState(0);
  const { toast } = useToast();

  const fetchHosts = async () => {
    try {
      setLoading(true);
      console.log("Fetching hosts for device ID:", deviceId);
      const response = await fetch(`/backend/api/devices.php?id=${deviceId}`);
      
      if (!response.ok) {
        throw new Error(`HTTP error: ${response.status}`);
      }
      
      const data = await response.json();
      console.log("Fetched device data:", data);
      
      if (data.connectedHosts && Array.isArray(data.connectedHosts)) {
        setHosts(data.connectedHosts.map((host: any) => ({
          id: host.id,
          ipAddress: host.ipAddress,
          hostname: host.hostname || 'Unknown Device',
          macAddress: host.macAddress,
          lastSeen: host.lastSeen,
          isActive: host.isActive
        })));
        setHostCount(data.connected_devices || 0); // Use the direct value from database
        console.log("Processed host data:", hosts);
      } else {
        console.log("No connected hosts found in the response");
        setHosts([]);
        setHostCount(0);
      }
    } catch (error) {
      console.error("Error fetching hosts:", error);
      toast({
        title: "Error",
        description: "Could not load connected hosts",
        variant: "destructive",
      });
    } finally {
      setLoading(false);
    }
  };

  const refreshRouter = async () => {
    try {
      setRefreshing(true);
      toast({
        title: "Refreshing",
        description: "Requesting latest data from router...",
      });
      
      console.log("Refreshing router data...");
      const response = await fetch('/backend/api/store_tr069_data.php', {
        method: 'POST'
      });
      
      if (!response.ok) {
        throw new Error(`HTTP error: ${response.status}`);
      }
      
      const result = await response.json();
      console.log("Refresh result:", result);
      
      if (result.success) {
        toast({
          title: "Success",
          description: `Updated ${result.parameters} parameters and ${result.hosts} hosts`,
          variant: "default",
        });
        
        // Refresh the host list
        await fetchHosts();
      } else {
        throw new Error(result.error || "Unknown error");
      }
    } catch (error) {
      console.error("Error refreshing router data:", error);
      toast({
        title: "Error",
        description: "Failed to refresh router data",
        variant: "destructive",
      });
    } finally {
      setRefreshing(false);
    }
  };

  useEffect(() => {
    console.log("ConnectedHosts component mounted or refreshTrigger changed");
    fetchHosts();
    
    const intervalId = setInterval(() => {
      console.log("Auto-refreshing hosts data");
      fetchHosts();
    }, 30000);
    
    return () => clearInterval(intervalId);
  }, [deviceId, refreshTrigger]);

  const getDeviceIcon = (hostname: string) => {
    const lowerHostname = hostname.toLowerCase();
    
    if (lowerHostname.includes("galaxy") || 
        lowerHostname.includes("iphone") || 
        lowerHostname.includes("android") ||
        lowerHostname.includes("mobile") ||
        lowerHostname.includes("phone")) {
      return <SmartphoneCharging className="h-4 w-4 text-purple-500" />;
    } else if (lowerHostname.includes("laptop") ||
               lowerHostname.includes("pc") ||
               lowerHostname.includes("windows") ||
               lowerHostname.includes("mac")) {
      return <Laptop className="h-4 w-4 text-blue-500" />;
    } else {
      return <Server className="h-4 w-4 text-gray-500" />;
    }
  };

  return (
    <Card className="p-6">
      <div className="mb-6 flex justify-between items-center">
        <div>
          <h3 className="text-lg font-semibold">Connected Clients</h3>
          <p className="text-sm text-gray-500">
            {hostCount} active {hostCount === 1 ? 'device' : 'devices'} on network
          </p>
        </div>
        <Button 
          variant="outline" 
          size="sm" 
          onClick={refreshRouter}
          disabled={refreshing}
        >
          <RefreshCw className={`h-4 w-4 mr-2 ${refreshing ? 'animate-spin' : ''}`} />
          Refresh Router Data
        </Button>
      </div>

      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-[40px]"></TableHead>
              <TableHead>Hostname</TableHead>
              <TableHead>IP Address</TableHead>
              <TableHead>MAC Address</TableHead>
              <TableHead>Last Seen</TableHead>
              <TableHead className="w-[80px]">Status</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {loading ? (
              <TableRow>
                <TableCell colSpan={6} className="text-center py-4">
                  Loading connected devices...
                </TableCell>
              </TableRow>
            ) : hosts.length === 0 ? (
              <TableRow>
                <TableCell colSpan={6} className="text-center py-4">
                  No connected devices found
                </TableCell>
              </TableRow>
            ) : (
              hosts.map((host, index) => (
                <TableRow key={host.id || index}>
                  <TableCell>
                    {getDeviceIcon(host.hostname || '')}
                  </TableCell>
                  <TableCell className="font-medium">
                    {host.hostname || 'Unknown Device'}
                  </TableCell>
                  <TableCell>{host.ipAddress}</TableCell>
                  <TableCell>{host.macAddress || 'N/A'}</TableCell>
                  <TableCell>
                    {host.lastSeen 
                      ? new Date(host.lastSeen).toLocaleString() 
                      : 'Unknown'}
                  </TableCell>
                  <TableCell>
                    <span className={`px-2 py-1 rounded-full text-xs font-medium ${
                      host.isActive 
                        ? 'bg-green-100 text-green-800' 
                        : 'bg-gray-100 text-gray-800'
                    }`}>
                      {host.isActive ? 'Active' : 'Inactive'}
                    </span>
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
