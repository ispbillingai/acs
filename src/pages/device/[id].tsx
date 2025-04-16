import { useRouter } from 'next/router';
import { useEffect, useState } from 'react';
import { Card } from "@/components/ui/card";
import { Device } from "@/types";
import { DeviceInfo } from "@/components/DeviceInfo";
import { DeviceActions } from "@/components/DeviceActions";
import { DeviceStats } from "@/components/DeviceStats";
import { DebugLogger } from "@/components/DebugLogger";
import { ConnectedClientsTable } from "@/components/ConnectedClientsTable";
import { DeviceParameters } from "@/components/DeviceParameters";

// Import new OpticalReadings component
import { OpticalReadings } from "@/components/OpticalReadings";

const DevicePage = () => {
  const router = useRouter();
  const { id: deviceId } = router.query;
  const [device, setDevice] = useState<Device | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [showDebug, setShowDebug] = useState(false);

  useEffect(() => {
    if (!deviceId) return;
    fetchDeviceData();
  }, [deviceId]);

  const fetchDeviceData = async () => {
    setIsLoading(true);
    try {
      const res = await fetch(`/api/devices/${deviceId}`);
      if (!res.ok) {
        throw new Error(`HTTP error! status: ${res.status}`);
      }
      const data = await res.json();
      setDevice(data);
    } catch (error) {
      console.error("Could not fetch device data:", error);
      setDevice(null);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="container mx-auto p-6">
      <div className="mb-6">
        <h1 className="text-3xl font-bold">{device?.serialNumber}</h1>
        <p className="text-gray-500">Device Details</p>
      </div>

      {isLoading ? (
        <div className="grid gap-6">
          {/* Loading skeleton */}
          <div className="h-40 bg-gray-100 animate-pulse rounded-lg"></div>
          <div className="h-60 bg-gray-100 animate-pulse rounded-lg"></div>
          <div className="h-40 bg-gray-100 animate-pulse rounded-lg"></div>
        </div>
      ) : device ? (
        <div className="grid gap-6">
          <DeviceStats device={device} />
          
          {/* Add the new OpticalReadings component */}
          <OpticalReadings device={device} onRefresh={() => fetchDeviceData()} />
          
          <DeviceInfo device={device} />
          
          <div className="flex justify-end">
            <DeviceActions device={device} onRefresh={() => fetchDeviceData()} />
          </div>
          
          {device.connectedClients && device.connectedClients > 0 && (
            <Card className="p-6">
              <h2 className="text-xl font-bold mb-4">Connected Clients</h2>
              <ConnectedClientsTable deviceId={deviceId} />
            </Card>
          )}
          
          <Card className="p-6">
            <h2 className="text-xl font-bold mb-4">Device Parameters</h2>
            <DeviceParameters deviceId={deviceId} />
          </Card>
          
          {showDebug && (
            <DebugLogger data={device} title="Device Raw Data" />
          )}
        </div>
      ) : (
        <div className="text-center p-12">
          <h2 className="text-2xl font-bold">Device not found</h2>
          <p className="text-gray-500 mt-2">The requested device could not be found.</p>
        </div>
      )}
    </div>
  );
};

export default DevicePage;
