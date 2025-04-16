
import React from "react";
import { BrowserRouter as Router, Routes, Route } from "react-router-dom";
import { SidebarProvider } from "@/components/ui/sidebar";
import { AppSidebar } from "@/components/AppSidebar";
import { Toaster } from "@/components/ui/sonner";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

// Import pages
import Index from "@/pages/Index";
import NotFound from "@/pages/NotFound";
import DeviceDetail from "@/pages/device/[id]";

// Create a react-query client
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      retry: 1,
      staleTime: 30000,
    },
  },
});

function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <Router>
        <SidebarProvider>
          <div className="flex min-h-screen w-full bg-blue-50">
            <AppSidebar />
            <main className="flex-1 p-4 md:p-6 overflow-auto">
              <div className="max-w-7xl mx-auto bg-white rounded-lg shadow-sm p-6 mb-6">
                <Routes>
                  <Route path="/" element={<Index />} />
                  <Route path="/devices/:id" element={<DeviceDetail />} />
                  <Route path="*" element={<NotFound />} />
                </Routes>
              </div>
            </main>
          </div>
        </SidebarProvider>
        <Toaster position="top-right" />
      </Router>
    </QueryClientProvider>
  );
}

export default App;
