
import { useParams } from "react-router-dom";
import { useState, useEffect } from "react";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { DeviceInfo } from "@/components/DeviceInfo";
import { DeviceParameters } from "@/components/DeviceParameters";
import { DebugLogger } from "@/components/DebugLogger";
import { ConnectedClientsTable } from "@/components/ConnectedClientsTable";
import { DeviceStats } from "@/components/DeviceStats";
import { DeviceActions } from "@/components/DeviceActions";
import { Device } from "@/types";

export default function DeviceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [device, setDevice] = useState<Device | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [debugVisible, setDebugVisible] = useState(false);

  useEffect(() => {
    const fetchDevice = async () => {
      setLoading(true);
      try {
        // For demo purposes, we'll create mock data
        const mockDevice: Device = {
          id: id || "1",
          serialNumber: "48575443F2D61173",
          manufacturer: "Huawei Technologies Co., Ltd",
          model: "HG8546M",
          status: "online",
          lastContact: new Date().toISOString(),
          ipAddress: "192.168.1.138",
          softwareVersion: "V5R019C10S125",
          hardwareVersion: "10C7.A",
          ssid: "TR069",
          connectedClients: 4,
          uptime: "39138"
        };
        
        console.log("Device data:", mockDevice);
        setDevice(mockDevice);
        setLoading(false);
      } catch (err) {
        console.error("Error fetching device:", err);
        setError("Failed to load device information");
        setLoading(false);
      }
    };

    if (id) {
      fetchDevice();
    }
  }, [id]);

  // Toggle debug information visibility
  const toggleDebug = () => {
    setDebugVisible(!debugVisible);
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-screen">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
        <span className="ml-3 text-lg">Loading device information...</span>
      </div>
    );
  }

  if (error || !device) {
    return (
      <div className="flex flex-col items-center justify-center h-screen">
        <div className="text-red-500 text-xl mb-4">
          <p>Error: {error || "Device not found"}</p>
        </div>
        <button
          onClick={() => window.history.back()}
          className="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600"
        >
          Go Back
        </button>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-4">
      <div className="flex flex-col md:flex-row justify-between items-start mb-6">
        <div>
          <h1 className="text-2xl font-bold mb-2 flex items-center">
            Device: {device.model}
            <span
              className={`ml-3 inline-block w-3 h-3 rounded-full ${
                device.status === "online" ? "bg-green-500" : "bg-red-500"
              }`}
            ></span>
            <span className="ml-2 text-sm font-normal text-gray-500">
              {device.status}
            </span>
          </h1>
          <p className="text-gray-600">Serial: {device.serialNumber}</p>
        </div>
        <div className="mt-4 md:mt-0">
          <DeviceActions deviceId={device.id} />
        </div>
      </div>

      <DeviceStats device={device} />

      <Tabs defaultValue="info" className="mt-6">
        <TabsList className="grid grid-cols-3 mb-6">
          <TabsTrigger value="info">Device Information</TabsTrigger>
          <TabsTrigger value="parameters">Parameters</TabsTrigger>
          <TabsTrigger value="clients">Connected Clients</TabsTrigger>
        </TabsList>

        <TabsContent value="info" className="space-y-4">
          <DeviceInfo device={device} />
          
          {/* Debug info toggle button */}
          <div className="mt-4 flex justify-end">
            <button
              onClick={toggleDebug}
              className="px-4 py-2 text-sm bg-gray-100 rounded border text-gray-700 hover:bg-gray-200"
            >
              {debugVisible ? "Hide Debug Info" : "Show Debug Info"}
            </button>
          </div>
          
          {/* Debug logger component */}
          {debugVisible && (
            <DebugLogger 
              data={device} 
              title="Device Debug Information" 
            />
          )}
        </TabsContent>

        <TabsContent value="parameters">
          <DeviceParameters deviceId={device.id} />
        </TabsContent>

        <TabsContent value="clients">
          <ConnectedClientsTable deviceId={device.id} />
        </TabsContent>
      </Tabs>
    </div>
  );
}
