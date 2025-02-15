
import { useState } from "react";
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

  // Mock data - replace with API call
  const parameters: Parameter[] = [
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

  const filteredParameters = parameters.filter((param) =>
    param.name.toLowerCase().includes(searchTerm.toLowerCase())
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
            {filteredParameters.map((param) => (
              <TableRow key={param.name}>
                <TableCell className="font-mono text-sm">{param.name}</TableCell>
                <TableCell>{param.value}</TableCell>
                <TableCell>{param.type}</TableCell>
                <TableCell>{param.writable ? "Yes" : "No"}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </Card>
  );
};
