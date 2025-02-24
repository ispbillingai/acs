
import { Card } from "@/components/ui/card";
import { WifiIcon, AlertCircle, CheckCircle2 } from "lucide-react";

interface Device {
  id: string;
  status: string;
}

interface DeviceStatsProps {
  devices: Device[];
}

interface StatsCardProps {
  title: string;
  value: number;
  icon: React.ReactNode;
}

const StatsCard = ({ title, value, icon }: StatsCardProps) => (
  <Card className="p-6 flex items-center justify-between animate-fade-down">
    <div>
      <p className="text-sm text-muted-foreground mb-1">{title}</p>
      <p className="text-2xl font-semibold">{value}</p>
    </div>
    <div className="h-12 w-12 bg-accent rounded-full flex items-center justify-center">
      {icon}
    </div>
  </Card>
);

export const DeviceStats = ({ devices }: DeviceStatsProps) => {
  const totalDevices = devices?.length || 0;
  const onlineDevices = devices?.filter(d => d.status === 'online').length || 0;
  const alertDevices = devices?.filter(d => d.status === 'offline').length || 0;

  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
      <StatsCard
        title="Total Devices"
        value={totalDevices}
        icon={<WifiIcon className="h-6 w-6" />}
      />
      <StatsCard
        title="Online"
        value={onlineDevices}
        icon={<CheckCircle2 className="h-6 w-6 text-success-dark" />}
      />
      <StatsCard
        title="Offline"
        value={alertDevices}
        icon={<AlertCircle className="h-6 w-6 text-error-dark" />}
      />
    </div>
  );
};
