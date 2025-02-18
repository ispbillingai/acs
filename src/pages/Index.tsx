
import { useQuery } from "@tanstack/react-query";
import { DeviceStats } from "@/components/DeviceStats";
import { DeviceCard } from "@/components/DeviceCard";
import { Skeleton } from "@/components/ui/skeleton";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { AlertCircle } from "lucide-react";

interface Device {
  id: string;
  serialNumber: string;
  model: string;
  status: "online" | "offline" | "provisioning";
  lastContact: string;
  ipAddress: string;
  manufacturer: string;
  softwareVersion?: string;
  hardwareVersion?: string;
}

// Fetch real devices from the backend with cache busting
const fetchDevices = async (): Promise<Device[]> => {
  console.log('Fetching devices...');
  const timestamp = new Date().getTime();
  const url = `/backend/api/devices.php?t=${timestamp}`;
  console.log('Fetching from URL:', url);
  
  const response = await fetch(url, {
    headers: {
      'Cache-Control': 'no-cache, no-store, must-revalidate',
      'Pragma': 'no-cache',
      'Expires': '0'
    }
  });
  
  if (!response.ok) {
    console.error('Failed to fetch devices:', response.status, response.statusText);
    throw new Error(`Failed to fetch devices: ${response.status} ${response.statusText}`);
  }
  
  const data = await response.json();
  console.log('Fetched devices:', data);
  return data;
};

const Index = () => {
  const { data: devices, isLoading, error } = useQuery<Device[], Error>({
    queryKey: ['devices'],
    queryFn: fetchDevices,
    refetchInterval: 5000,
    refetchOnWindowFocus: true,
    staleTime: 0,
    gcTime: 0,
    retry: 3
  });

  console.log('Current render state:', { 
    devices, 
    isLoading, 
    error, 
    hasDevices: Boolean(devices?.length)
  });

  if (isLoading) {
    console.log('Rendering loading state...');
    return (
      <div className="min-h-screen bg-background p-6">
        <div className="max-w-7xl mx-auto space-y-8">
          <div className="space-y-2">
            <Skeleton className="h-8 w-48" />
            <Skeleton className="h-4 w-64" />
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            {[1, 2, 3].map((n) => (
              <Skeleton key={n} className="h-[200px]" />
            ))}
          </div>
        </div>
      </div>
    );
  }

  if (error) {
    console.log('Rendering error state:', error);
    return (
      <div className="min-h-screen bg-background p-6">
        <div className="max-w-7xl mx-auto">
          <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertDescription>
              Error loading devices. Please try again later.
              {error instanceof Error ? ` (${error.message})` : ''}
            </AlertDescription>
          </Alert>
        </div>
      </div>
    );
  }

  console.log('Rendering success state with devices:', devices);
  return (
    <div className="min-h-screen bg-background p-6">
      <div className="max-w-7xl mx-auto space-y-8">
        <div className="space-y-2">
          <h1 className="text-3xl font-semibold tracking-tight">
            ACS Dashboard
          </h1>
          <p className="text-muted-foreground">
            Monitor and manage your TR-069 devices
          </p>
        </div>

        <DeviceStats devices={devices || []} />

        <div>
          <h2 className="text-xl font-semibold mb-4">Connected Devices</h2>
          {!devices || devices.length === 0 ? (
            <Alert>
              <AlertDescription>
                No devices connected yet. Devices will appear here when they connect to the ACS.
              </AlertDescription>
            </Alert>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              {devices.map((device) => (
                <DeviceCard key={device.id} device={device} />
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default Index;
