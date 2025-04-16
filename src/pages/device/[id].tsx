
import { useState, useEffect } from "react";
import { useParams } from "react-router-dom";
import { DeviceInfo } from "@/components/DeviceInfo";
import { DeviceParameters } from "@/components/DeviceParameters";
import { DeviceActions } from "@/components/DeviceActions";
import { ConnectedHosts } from "@/components/ConnectedHosts";
import { DeviceStats } from "@/components/DeviceStats";

interface Device {
  id: string;
  serialNumber: string;
  manufacturer: string;
  model: string;
  status: string;
  lastContact: string;
  ipAddress: string;
  softwareVersion?: string;
  hardwareVersion?: string;
  connectedClients?: number;
  uptime?: string;
}

const DevicePage = () => {
  const { id } = useParams<{ id: string }>();
  const [device, setDevice] = useState<Device | null>(null);
  const [devices, setDevices] = useState<Device[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchDeviceData = async () => {
      try {
        // Fetch device data
        const response = await fetch(`/api/devices.php?id=${id}`);
        if (response.ok) {
          const data = await response.json();
          setDevice(data);
          
          // Also set the devices array with just this device
          // This helps compatibility with components expecting an array
          setDevices([data]);
        } else {
          console.error("Failed to fetch device data");
        }
      } catch (error) {
        console.error("Error fetching device data:", error);
      } finally {
        setLoading(false);
      }
    };

    if (id) {
      fetchDeviceData();
    }
  }, [id]);

  if (loading) {
    return <div className="flex justify-center items-center min-h-screen">Loading device data...</div>;
  }

  if (!device) {
    return <div className="flex justify-center items-center min-h-screen">Device not found</div>;
  }

  return (
    <div className="container mx-auto p-6 space-y-8">
      <h1 className="text-2xl font-bold mb-8">
        {device.manufacturer} {device.model} - {device.serialNumber}
      </h1>

      <DeviceStats devices={devices} />

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <DeviceInfo device={device} />
        <DeviceActions device={device} />
      </div>

      <DeviceParameters deviceId={id || ""} />

      {device.connectedClients && device.connectedClients > 0 && (
        <ConnectedHosts deviceId={id || ""} />
      )}
    </div>
  );
};

export default DevicePage;
