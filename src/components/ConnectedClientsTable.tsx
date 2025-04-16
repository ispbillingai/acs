
import React from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { WifiIcon, SignalIcon } from "lucide-react";
import { ConnectedClient } from "@/types";

interface ConnectedClientsTableProps {
  clients: ConnectedClient[];
}

export const ConnectedClientsTable: React.FC<ConnectedClientsTableProps> = ({ clients }) => {
  if (!clients || clients.length === 0) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <WifiIcon className="h-5 w-5 text-blue-500" />
          Connected Clients
        </CardTitle>
        <CardDescription>
          {clients.length} active device{clients.length !== 1 ? "s" : ""} connected to this router
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Hostname</TableHead>
              <TableHead>IP Address</TableHead>
              <TableHead>MAC Address</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Last Seen</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {clients.map((client) => (
              <TableRow key={client.id}>
                <TableCell className="font-medium">{client.hostname || "Unknown Device"}</TableCell>
                <TableCell>{client.ipAddress}</TableCell>
                <TableCell>{client.macAddress}</TableCell>
                <TableCell>
                  <div className="flex items-center gap-2">
                    <SignalIcon 
                      className={`h-4 w-4 ${client.isActive ? "text-success-dark" : "text-muted-foreground"}`} 
                    />
                    {client.isActive ? "Active" : "Inactive"}
                  </div>
                </TableCell>
                <TableCell>{new Date(client.lastSeen).toLocaleString()}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  );
};
