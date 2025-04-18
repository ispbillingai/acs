
// Update device page component to use connectedDevices instead of connectedClients
import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { Device } from "@/types";
import { DeviceInfo } from "@/components/DeviceInfo";
import { DeviceStats } from "@/components/DeviceStats";
import { ConnectedHosts } from "@/components/ConnectedHosts";
import { DeviceParameters } from "@/components/DeviceParameters";
import { DeviceActions } from "@/components/DeviceActions";
import { OpticalReadings } from "@/components/OpticalReadings";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { DebugLogger } from "@/components/DebugLogger";
import { Card } from "@/components/ui/card";

export default function DevicePage() {
  const { id } = useParams<{ id: string }>();
  const [device, setDevice] = useState<Device | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshTrigger, setRefreshTrigger] = useState(0);
  const [showDebug, setShowDebug] = useState(false);

  useEffect(() => {
    if (!id) return;

    const fetchDevice = async () => {
      try {
        setLoading(true);
        const response = await fetch(`/backend/api/devices.php?id=${id}`);
        
        if (!response.ok) {
          throw new Error(`HTTP error: ${response.status}`);
        }
        
        const data = await response.json();
        console.log("Fetched device data:", data);
        
        // Map data to Device type
        const deviceData: Device = {
          id: data.id,
          serialNumber: data.serialNumber,
          manufacturer: data.manufacturer,
          model: data.model,
          status: data.status,
          lastContact: data.lastContact,
          ipAddress: data.ipAddress,
          softwareVersion: data.softwareVersion,
          hardwareVersion: data.hardwareVersion,
          ssid: data.ssid,
          ssidPassword: data.ssidPassword,
          uptime: data.uptime,
          connectedDevices: data.connectedDevices,
          txPower: data.txPower,
          rxPower: data.rxPower
        };
        
        setDevice(deviceData);
      } catch (error) {
        console.error("Error fetching device:", error);
        setError("Failed to load device information");
      } finally {
        setLoading(false);
      }
    };

    fetchDevice();
  }, [id, refreshTrigger]);

  const handleRefresh = () => {
    setRefreshTrigger(prev => prev + 1);
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-blue-500"></div>
      </div>
    );
  }

  if (error || !device) {
    return (
      <div className="text-center p-6">
        <h2 className="text-xl text-red-600 mb-2">Error</h2>
        <p>{error || "Device not found"}</p>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-4 space-y-6">
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold">Device Details</h1>
        <div className="flex space-x-2">
          <button 
            onClick={handleRefresh}
            className="px-4 py-2 bg-blue-100 hover:bg-blue-200 text-blue-800 rounded-md transition-colors"
          >
            Refresh Data
          </button>
          <button 
            onClick={() => setShowDebug(!showDebug)}
            className="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-800 rounded-md transition-colors"
          >
            {showDebug ? "Hide Debug" : "Show Debug"}
          </button>
        </div>
      </div>
      
      {showDebug && (
        <Card className="p-4 mb-6">
          <h2 className="text-lg font-semibold mb-2">Debug Information</h2>
          <DebugLogger data={device} title="Device Data" />
        </Card>
      )}
      
      <DeviceStats device={device} />
      
      <DeviceInfo device={device} />
      
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <OpticalReadings device={device} onRefresh={handleRefresh} />
        <DeviceActions deviceId={device.id} />
      </div>
      
      <Tabs defaultValue="hosts">
        <TabsList className="mb-4">
          <TabsTrigger value="hosts">Connected Clients</TabsTrigger>
          <TabsTrigger value="parameters">Device Parameters</TabsTrigger>
        </TabsList>
        <TabsContent value="hosts">
          <ConnectedHosts deviceId={device.id} refreshTrigger={refreshTrigger} />
        </TabsContent>
        <TabsContent value="parameters">
          <DeviceParameters deviceId={device.id} refreshTrigger={refreshTrigger} />
        </TabsContent>
      </Tabs>
    </div>
  );
}
