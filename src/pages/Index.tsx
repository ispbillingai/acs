
import React from 'react';
import DeviceStats from '../components/DeviceStats';
import DeviceCard from '../components/DeviceCard';

const Index = () => {
  return (
    <div className="p-6">
      <div className="max-w-7xl mx-auto space-y-8">
        <div className="space-y-2">
          <h1 className="text-3xl font-semibold tracking-tight">
            ACS Dashboard
          </h1>
          <p className="text-gray-600">
            Monitor and manage your TR-069 devices
          </p>
        </div>
        <DeviceStats />
        <DeviceCard />
      </div>
    </div>
  );
};

export default Index;
