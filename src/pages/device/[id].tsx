
import { useParams } from "react-router-dom";
import { Card } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { DeviceInfo } from "@/components/DeviceInfo";
import { DeviceParameters } from "@/components/DeviceParameters";
import { DeviceActions } from "@/components/DeviceActions";
import { useQuery } from "@tanstack/react-query";
import { Skeleton } from "@/components/ui/skeleton";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { AlertCircle } from "lucide-react";

// Fetch device details with cache busting
const fetchDevice = async (id: string) => {
  const timestamp = new Date().getTime();
  const response = await fetch(`/backend/api/devices.php?id=${id}&t=${timestamp}`, {
    headers: {
      'Cache-Control': 'no-cache, no-store, must-revalidate',
      'Pragma': 'no-cache',
      'Expires': '0'
    }
  });
  
  if (!response.ok) {
    throw new Error('Failed to fetch device details');
  }
  
  const data = await response.json();
  console.log('Fetched device details:', data);
  return data;
};

const DevicePage = () => {
  const { id } = useParams();
  
  const { data: device, isLoading, error } = useQuery({
    queryKey: ['device', id],
    queryFn: () => fetchDevice(id!),
    refetchInterval: 5000,
    refetchOnWindowFocus: true,
    staleTime: 0,
    gcTime: 0
  });

  if (isLoading) {
    return (
      <div className="min-h-screen bg-background p-6">
        <div className="max-w-7xl mx-auto space-y-6">
          <Skeleton className="h-12 w-1/3" />
          <Skeleton className="h-[200px] w-full" />
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="min-h-screen bg-background p-6">
        <div className="max-w-7xl mx-auto">
          <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertDescription>
              Error loading device details. Please try again later.
              {error instanceof Error ? ` (${error.message})` : ''}
            </AlertDescription>
          </Alert>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-background p-6">
      <div className="max-w-7xl mx-auto space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-semibold tracking-tight mb-1">
              {device.model}
            </h1>
            <p className="text-muted-foreground">{device.serialNumber}</p>
          </div>
          <DeviceActions device={device} />
        </div>

        <Tabs defaultValue="info" className="w-full">
          <TabsList>
            <TabsTrigger value="info">Device Info</TabsTrigger>
            <TabsTrigger value="parameters">Parameters</TabsTrigger>
            <TabsTrigger value="logs">Logs</TabsTrigger>
          </TabsList>
          <TabsContent value="info">
            <DeviceInfo device={device} />
          </TabsContent>
          <TabsContent value="parameters">
            <DeviceParameters deviceId={device.id} />
          </TabsContent>
          <TabsContent value="logs">
            <Card className="p-6">
              <p className="text-muted-foreground">Coming soon...</p>
            </Card>
          </TabsContent>
        </Tabs>
      </div>
    </div>
  );
};

export default DevicePage;
