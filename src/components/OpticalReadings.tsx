
import { useState } from 'react';
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { ArrowDownIcon, ArrowUpIcon, RefreshCwIcon, SignalIcon, ZapIcon } from "lucide-react";
import { Device } from "@/types";

interface OpticalReadingsProps {
  device: Device;
  onRefresh?: () => void;
}

export const OpticalReadings = ({ device, onRefresh }: OpticalReadingsProps) => {
  const [isLoading, setIsLoading] = useState(false);
  
  const handleRefresh = () => {
    if (onRefresh) {
      setIsLoading(true);
      // Simulate loading state
      setTimeout(() => {
        onRefresh();
        setIsLoading(false);
      }, 1000);
    } else {
      setIsLoading(true);
      // Fallback to page reload if no refresh handler provided
      window.location.reload();
    }
  };

  const hasPowerReadings = device.txPower || device.rxPower;
  
  return (
    <Card className="p-6 bg-gradient-to-br from-white to-purple-50 border border-purple-100 shadow-md">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-lg font-semibold text-purple-900 flex items-center">
          <SignalIcon className="h-5 w-5 mr-2 text-purple-700" />
          Optical Signal Readings
        </h3>
        <Button 
          variant="outline" 
          size="sm" 
          onClick={handleRefresh} 
          disabled={isLoading}
          className="border-purple-200 hover:bg-purple-100"
        >
          <RefreshCwIcon className={`h-4 w-4 mr-2 ${isLoading ? 'animate-spin' : ''}`} />
          Refresh Readings
        </Button>
      </div>

      {hasPowerReadings ? (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div className="bg-white p-4 rounded-lg border border-purple-100 shadow-sm">
            <div className="flex items-center mb-2 text-green-600">
              <ArrowUpIcon className="h-4 w-4 mr-2" />
              <span className="text-sm font-medium">TX Power (Transmit)</span>
            </div>
            <div className="flex items-center">
              <ZapIcon className="h-5 w-5 mr-2 text-green-500" />
              <span className="text-2xl font-bold">{device.txPower || 'N/A'}</span>
            </div>
            <p className="text-xs text-gray-500 mt-1">Signal strength from device to network</p>
          </div>

          <div className="bg-white p-4 rounded-lg border border-purple-100 shadow-sm">
            <div className="flex items-center mb-2 text-blue-600">
              <ArrowDownIcon className="h-4 w-4 mr-2" />
              <span className="text-sm font-medium">RX Power (Receive)</span>
            </div>
            <div className="flex items-center">
              <ZapIcon className="h-5 w-5 mr-2 text-blue-500" />
              <span className="text-2xl font-bold">{device.rxPower || 'N/A'}</span>
            </div>
            <p className="text-xs text-gray-500 mt-1">Signal strength from network to device</p>
          </div>
        </div>
      ) : (
        <div className="bg-gray-50 p-4 rounded-lg border border-gray-200 text-center">
          <p className="text-gray-500">No optical readings available for this device.</p>
          <p className="text-gray-400 text-sm mt-1">Try refreshing or check device compatibility.</p>
        </div>
      )}
    </Card>
  );
};
