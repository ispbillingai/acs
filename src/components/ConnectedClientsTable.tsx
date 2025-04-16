
import React from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { WifiIcon, SignalIcon, Laptop, Smartphone, Server } from "lucide-react";
import { ConnectedClient } from "@/types";

interface ConnectedClientsTableProps {
  clients: ConnectedClient[];
}

export const ConnectedClientsTable: React.FC<ConnectedClientsTableProps> = ({ clients }) => {
  if (!clients || clients.length === 0) {
    return null;
  }

  // Function to guess device type from hostname
  const getDeviceIcon = (hostname: string) => {
    const lowercaseHostname = (hostname || "").toLowerCase();
    
    if (lowercaseHostname.includes("galaxy") || 
        lowercaseHostname.includes("iphone") || 
        lowercaseHostname.includes("android") ||
        lowercaseHostname.includes("mobile") ||
        lowercaseHostname.includes("a04s")) {
      return <Smartphone className="h-4 w-4 text-purple-500" />;
    } else if (lowercaseHostname.includes("pc") || 
              lowercaseHostname.includes("laptop") || 
              lowercaseHostname.includes("desktop") ||
              lowercaseHostname.includes("mac")) {
      return <Laptop className="h-4 w-4 text-blue-500" />;
    } else {
      return <Server className="h-4 w-4 text-gray-500" />;
    }
  };

  return (
    <Card className="bg-gradient-to-br from-white to-purple-50 border border-purple-100 shadow-md">
      <CardHeader className="bg-white bg-opacity-70 backdrop-blur-sm border-b border-purple-100">
        <CardTitle className="flex items-center gap-2 text-purple-800">
          <WifiIcon className="h-5 w-5 text-purple-500" />
          Connected Clients
        </CardTitle>
        <CardDescription className="text-purple-700">
          {clients.length} active device{clients.length !== 1 ? "s" : ""} connected to this router
        </CardDescription>
      </CardHeader>
      <CardContent className="p-4">
        <div className="overflow-x-auto rounded-md border border-purple-100 bg-white">
          <Table>
            <TableHeader className="bg-purple-50">
              <TableRow>
                <TableHead className="text-purple-800">Device</TableHead>
                <TableHead className="text-purple-800">Hostname</TableHead>
                <TableHead className="text-purple-800">IP Address</TableHead>
                <TableHead className="text-purple-800">MAC Address</TableHead>
                <TableHead className="text-purple-800">Status</TableHead>
                <TableHead className="text-purple-800">Last Seen</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {clients.map((client) => (
                <TableRow key={client.id} className="hover:bg-purple-50 transition-colors">
                  <TableCell>{getDeviceIcon(client.hostname)}</TableCell>
                  <TableCell className="font-medium">{client.hostname || "Unknown Device"}</TableCell>
                  <TableCell>{client.ipAddress}</TableCell>
                  <TableCell>{client.macAddress}</TableCell>
                  <TableCell>
                    <div className="flex items-center gap-2">
                      <SignalIcon 
                        className={`h-4 w-4 ${client.isActive ? "text-success-dark" : "text-muted-foreground"}`} 
                      />
                      <span className={client.isActive ? "text-green-600" : "text-gray-500"}>
                        {client.isActive ? "Active" : "Inactive"}
                      </span>
                    </div>
                  </TableCell>
                  <TableCell>{new Date(client.lastSeen).toLocaleString()}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </CardContent>
    </Card>
  );
};
