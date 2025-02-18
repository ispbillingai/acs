
import { useQuery } from "@tanstack/react-query";

// Fetch real devices from the backend with cache busting
const fetchDevices = async () => {
  const timestamp = new Date().getTime();
  const response = await fetch(`/backend/api/devices.php?t=${timestamp}`, {
    headers: {
      'Cache-Control': 'no-cache, no-store, must-revalidate',
      'Pragma': 'no-cache',
      'Expires': '0'
    }
  });
  
  if (!response.ok) {
    console.error('Failed to fetch devices:', response.status, response.statusText);
    throw new Error('Failed to fetch devices');
  }
  
  const data = await response.json();
  console.log('Fetched devices:', data);
  return data;
};

const Index = () => {
  const { data: devices, isLoading, error } = useQuery({
    queryKey: ['devices'],
    queryFn: fetchDevices,
    refetchInterval: 5000,
    refetchOnWindowFocus: true,
    staleTime: 0,
    gcTime: 0, // Changed from cacheTime to gcTime for newer React Query
    retry: 3
  });

  if (isLoading) return <div>Loading...</div>;
  if (error) return <div>Error: {error instanceof Error ? error.message : 'Unknown error'}</div>;

  // Raw data display
  return (
    <div className="p-4">
      <h1>Raw Device Data:</h1>
      <pre className="bg-gray-100 p-4 mt-4 rounded overflow-auto">
        {JSON.stringify(devices, null, 2)}
      </pre>
    </div>
  );
};

export default Index;
