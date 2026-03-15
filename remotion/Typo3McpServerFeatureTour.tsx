import React from 'react';
import {
  AbsoluteFill,
  Sequence,
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
} from 'remotion';

export const FEATURE_TOUR_WIDTH = 1920;
export const FEATURE_TOUR_HEIGHT = 1080;
export const FEATURE_TOUR_FPS = 30;
const SCENE_DURATION = 120;
const SCENE_GAP = 0;

const COLORS = {
  background: '#07111f',
  backgroundAlt: '#0d1d31',
  surface: 'rgba(18, 35, 56, 0.8)',
  surfaceStrong: 'rgba(18, 35, 56, 0.96)',
  border: 'rgba(133, 183, 255, 0.18)',
  primary: '#7cc4ff',
  accent: '#ff9a57',
  accentSoft: 'rgba(255, 154, 87, 0.18)',
  text: '#f5f7fb',
  muted: '#b7c7db',
  success: '#7de2bb',
};

type SceneProps = {
  title: string;
  kicker: string;
  body: React.ReactNode;
  sceneFrame: number;
};

const SceneShell: React.FC<SceneProps> = ({title, kicker, body, sceneFrame}) => {
  const {fps} = useVideoConfig();
  const entrance = spring({
    fps,
    frame: sceneFrame,
    config: {
      damping: 18,
      stiffness: 110,
      mass: 0.6,
    },
  });
  const fade = interpolate(sceneFrame, [0, 18, 96, 118], [0, 1, 1, 0], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  const slideUp = interpolate(sceneFrame, [0, 20], [34, 0], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  return (
    <AbsoluteFill
      style={{
        opacity: fade,
        color: COLORS.text,
        fontFamily:
          'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
        padding: 86,
      }}
    >
      <Backdrop sceneFrame={sceneFrame} />
      <div
        style={{
          position: 'relative',
          zIndex: 2,
          display: 'flex',
          flexDirection: 'column',
          gap: 34,
          height: '100%',
          transform: `translateY(${slideUp}px) scale(${0.96 + entrance * 0.04})`,
        }}
      >
        <div style={{display: 'flex', flexDirection: 'column', gap: 18}}>
          <div
            style={{
              alignSelf: 'flex-start',
              fontSize: 24,
              fontWeight: 700,
              letterSpacing: '0.22em',
              textTransform: 'uppercase',
              color: COLORS.primary,
              padding: '12px 18px',
              borderRadius: 999,
              border: `1px solid ${COLORS.border}`,
              background: 'rgba(12, 28, 47, 0.72)',
            }}
          >
            {kicker}
          </div>
          <h1
            style={{
              margin: 0,
              fontSize: 78,
              lineHeight: 1.02,
              letterSpacing: '-0.05em',
              maxWidth: 1200,
            }}
          >
            {title}
          </h1>
        </div>
        <div style={{flex: 1, display: 'flex'}}>{body}</div>
      </div>
    </AbsoluteFill>
  );
};

const Backdrop: React.FC<{sceneFrame: number}> = ({sceneFrame}) => {
  const driftA = interpolate(sceneFrame, [0, SCENE_DURATION], [0, 140]);
  const driftB = interpolate(sceneFrame, [0, SCENE_DURATION], [0, -110]);
  const glow = interpolate(sceneFrame, [0, 40, 120], [0.3, 0.9, 0.45], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  return (
    <AbsoluteFill
      style={{
        overflow: 'hidden',
        background: `radial-gradient(circle at top left, rgba(32, 71, 119, 0.55), transparent 38%),
          linear-gradient(160deg, ${COLORS.background} 0%, ${COLORS.backgroundAlt} 100%)`,
      }}
    >
      <div
        style={{
          position: 'absolute',
          width: 820,
          height: 820,
          borderRadius: 999,
          top: -220,
          left: -120 + driftA,
          background: `radial-gradient(circle, rgba(124, 196, 255, ${0.14 * glow}), transparent 66%)`,
          filter: 'blur(10px)',
        }}
      />
      <div
        style={{
          position: 'absolute',
          width: 700,
          height: 700,
          borderRadius: 999,
          right: -120 + driftB,
          bottom: -200,
          background: `radial-gradient(circle, rgba(255, 154, 87, ${0.16 * glow}), transparent 66%)`,
          filter: 'blur(14px)',
        }}
      />
      <div
        style={{
          position: 'absolute',
          inset: 0,
          backgroundImage:
            'linear-gradient(rgba(255,255,255,0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.04) 1px, transparent 1px)',
          backgroundSize: '64px 64px',
          maskImage:
            'linear-gradient(to bottom, rgba(255,255,255,0.65), transparent 88%)',
          opacity: 0.22,
        }}
      />
    </AbsoluteFill>
  );
};

const GlassCard: React.FC<{
  children: React.ReactNode;
  width?: number | string;
  minHeight?: number;
}> = ({children, width = 'auto', minHeight}) => {
  return (
    <div
      style={{
        width,
        minHeight,
        borderRadius: 32,
        border: `1px solid ${COLORS.border}`,
        background: COLORS.surface,
        boxShadow: '0 26px 60px rgba(0, 0, 0, 0.28)',
        padding: 32,
        backdropFilter: 'blur(16px)',
      }}
    >
      {children}
    </div>
  );
};

const FeatureGrid: React.FC<{
  sceneFrame: number;
  items: Array<{title: string; text: string}>;
}> = ({sceneFrame, items}) => {
  const {fps} = useVideoConfig();

  return (
    <div
      style={{
        display: 'grid',
        gridTemplateColumns: 'repeat(2, minmax(0, 1fr))',
        gap: 22,
        width: '100%',
      }}
    >
      {items.map((item, index) => {
        const localFrame = Math.max(0, sceneFrame - index * 7);
        const scale = spring({
          fps,
          frame: localFrame,
          config: {damping: 15, stiffness: 130},
        });
        const opacity = interpolate(localFrame, [0, 18], [0, 1], {
          extrapolateLeft: 'clamp',
          extrapolateRight: 'clamp',
        });

        return (
          <div
            key={item.title}
            style={{
              transform: `translateY(${(1 - scale) * 36}px) scale(${0.94 + scale * 0.06})`,
              opacity,
            }}
          >
            <GlassCard minHeight={188}>
              <div style={{display: 'flex', flexDirection: 'column', gap: 14}}>
                <div
                  style={{
                    fontSize: 27,
                    fontWeight: 700,
                    color: COLORS.text,
                    letterSpacing: '-0.03em',
                  }}
                >
                  {item.title}
                </div>
                <div
                  style={{
                    fontSize: 22,
                    lineHeight: 1.5,
                    color: COLORS.muted,
                  }}
                >
                  {item.text}
                </div>
              </div>
            </GlassCard>
          </div>
        );
      })}
    </div>
  );
};

const FlowDiagram: React.FC<{sceneFrame: number}> = ({sceneFrame}) => {
  const {fps} = useVideoConfig();
  const boxes = [
    {label: 'MCP client', x: 0},
    {label: 'TYPO3 MCP Server', x: 420},
    {label: 'Workspace review', x: 840},
  ];

  return (
    <div style={{display: 'flex', flexDirection: 'column', gap: 30, width: '100%'}}>
      <GlassCard>
        <div
          style={{
            position: 'relative',
            height: 260,
          }}
        >
          {boxes.map((box, index) => {
            const localFrame = Math.max(0, sceneFrame - index * 6);
            const scale = spring({
              fps,
              frame: localFrame,
              config: {damping: 16, stiffness: 120},
            });

            return (
              <div
                key={box.label}
                style={{
                  position: 'absolute',
                  top: 60,
                  left: box.x,
                  width: 320,
                  height: 140,
                  borderRadius: 28,
                  border: `1px solid ${COLORS.border}`,
                  background: COLORS.surfaceStrong,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  fontSize: 32,
                  fontWeight: 700,
                  transform: `translateY(${(1 - scale) * 28}px) scale(${0.92 + scale * 0.08})`,
                  boxShadow: '0 18px 42px rgba(0, 0, 0, 0.22)',
                }}
              >
                {box.label}
              </div>
            );
          })}
          {[0, 1].map((lineIndex) => {
            const progress = interpolate(
              sceneFrame,
              [20 + lineIndex * 18, 54 + lineIndex * 18],
              [0, 1],
              {
                extrapolateLeft: 'clamp',
                extrapolateRight: 'clamp',
              },
            );

            return (
              <div
                key={lineIndex}
                style={{
                  position: 'absolute',
                  top: 126,
                  left: 304 + lineIndex * 420,
                  width: 112,
                  height: 8,
                  borderRadius: 999,
                  background: `linear-gradient(90deg, ${COLORS.primary}, ${COLORS.accent})`,
                  transformOrigin: 'left center',
                  transform: `scaleX(${progress})`,
                }}
              />
            );
          })}
        </div>
      </GlassCard>
      <FeatureGrid
        sceneFrame={sceneFrame + 10}
        items={[
          {
            title: 'Schema-aware',
            text: 'Clients can inspect TCA and FlexForms before writing.',
          },
          {
            title: 'Workspace-first',
            text: 'Record changes stay reviewable before they reach the live site.',
          },
        ]}
      />
    </div>
  );
};

const ToolRow: React.FC<{sceneFrame: number; title: string; items: string[]}> = ({
  sceneFrame,
  title,
  items,
}) => {
  return (
    <GlassCard>
      <div style={{display: 'flex', flexDirection: 'column', gap: 18}}>
        <div style={{fontSize: 30, fontWeight: 700}}>{title}</div>
        <div style={{display: 'flex', flexWrap: 'wrap', gap: 14}}>
          {items.map((item, index) => {
            const localFrame = Math.max(0, sceneFrame - index * 4);
            const opacity = interpolate(localFrame, [0, 14], [0, 1], {
              extrapolateLeft: 'clamp',
              extrapolateRight: 'clamp',
            });
            const slide = interpolate(localFrame, [0, 14], [18, 0], {
              extrapolateLeft: 'clamp',
              extrapolateRight: 'clamp',
            });

            return (
              <div
                key={item}
                style={{
                  opacity,
                  transform: `translateY(${slide}px)`,
                  padding: '14px 18px',
                  borderRadius: 999,
                  border: `1px solid ${COLORS.border}`,
                  background: 'rgba(9, 26, 44, 0.72)',
                  fontSize: 22,
                  fontWeight: 600,
                  color: COLORS.text,
                }}
              >
                {item}
              </div>
            );
          })}
        </div>
      </div>
    </GlassCard>
  );
};

const FileHarnessScene: React.FC<{sceneFrame: number}> = ({sceneFrame}) => {
  const pulse = interpolate(sceneFrame, [0, 60, 120], [0.7, 1, 0.82], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  return (
    <div
      style={{
        display: 'grid',
        gridTemplateColumns: '1.05fr 1fr',
        gap: 24,
        width: '100%',
      }}
    >
      <GlassCard>
        <div style={{display: 'flex', flexDirection: 'column', gap: 18}}>
          <div
            style={{
              fontSize: 30,
              fontWeight: 700,
              color: COLORS.text,
            }}
          >
            File harness
          </div>
          <div
            style={{
              fontSize: 23,
              lineHeight: 1.55,
              color: COLORS.muted,
            }}
          >
            File tools are sandboxed to a configurable root such as
            <span style={{color: COLORS.text}}> `1:/mcp/`</span> instead of
            browsing unrestricted ``fileadmin`` paths.
          </div>
          <div
            style={{
              marginTop: 8,
              borderRadius: 28,
              padding: 26,
              background: 'rgba(8, 22, 38, 0.82)',
              border: `1px solid ${COLORS.border}`,
            }}
          >
            <div style={{fontSize: 22, color: COLORS.primary, marginBottom: 16}}>
              Default upload pattern
            </div>
            <div
              style={{
                fontSize: 27,
                fontWeight: 700,
                lineHeight: 1.4,
                color: COLORS.text,
              }}
            >
              1:/mcp/workspaces/ws-3/images/
            </div>
          </div>
        </div>
      </GlassCard>
      <div style={{display: 'flex', flexDirection: 'column', gap: 22}}>
        <FeatureGrid
          sceneFrame={sceneFrame}
          items={[
            {
              title: 'UploadFile',
              text: 'Uploads binary files into the harness with randomized stored names.',
            },
            {
              title: 'WriteFile',
              text: 'Creates or updates text-based files inside the same sandbox.',
            },
            {
              title: 'BrowseFiles',
              text: 'Explains the harness root and only exposes allowed folders.',
            },
            {
              title: 'Metadata',
              text: 'Read and update metadata for files that stay inside the harness.',
            },
          ]}
        />
        <div
          style={{
            alignSelf: 'flex-end',
            padding: '14px 20px',
            borderRadius: 999,
            border: `1px solid ${COLORS.accent}`,
            background: COLORS.accentSoft,
            fontSize: 20,
            fontWeight: 700,
            color: COLORS.text,
            transform: `scale(${pulse})`,
          }}
        >
          Safer than unrestricted fileadmin access
        </div>
      </div>
    </div>
  );
};

const ModuleScene: React.FC<{sceneFrame: number}> = ({sceneFrame}) => {
  const rows = [
    'Remote MCP setup with OAuth instructions',
    'Local CLI setup for development clients',
    'Workspace context visibility',
    'Token creation and revocation',
  ];

  return (
    <div
      style={{
        display: 'grid',
        gridTemplateColumns: '0.95fr 1.05fr',
        gap: 24,
        width: '100%',
      }}
    >
      <GlassCard minHeight={500}>
        <div style={{display: 'flex', flexDirection: 'column', gap: 16}}>
          <div style={{fontSize: 32, fontWeight: 700}}>Backend module</div>
          <div style={{fontSize: 22, lineHeight: 1.55, color: COLORS.muted}}>
            Editors configure the extension from the User / MCP Server module, where the
            endpoint, workspaces, and client setup flow stay visible.
          </div>
          <div style={{display: 'flex', flexDirection: 'column', gap: 14, marginTop: 8}}>
            {rows.map((row, index) => {
              const localFrame = Math.max(0, sceneFrame - index * 5);
              const opacity = interpolate(localFrame, [0, 16], [0, 1], {
                extrapolateLeft: 'clamp',
                extrapolateRight: 'clamp',
              });

              return (
                <div
                  key={row}
                  style={{
                    opacity,
                    padding: '18px 20px',
                    borderRadius: 22,
                    background: 'rgba(9, 26, 44, 0.76)',
                    border: `1px solid ${COLORS.border}`,
                    fontSize: 22,
                    lineHeight: 1.4,
                  }}
                >
                  {row}
                </div>
              );
            })}
          </div>
        </div>
      </GlassCard>
      <FeatureGrid
        sceneFrame={sceneFrame + 12}
        items={[
          {
            title: 'OAuth 2.1 + PKCE',
            text: 'Remote clients get a proper authentication flow instead of shared secrets.',
          },
          {
            title: 'Multiple client paths',
            text: 'Supports Claude-style remote MCP and local stdio workflows.',
          },
          {
            title: 'Editor visibility',
            text: 'The TYPO3 backend remains the place where editors review and publish.',
          },
          {
            title: 'No hidden magic',
            text: 'Connection, workspace, and file behavior stay explicit in the module UI.',
          },
        ]}
      />
    </div>
  );
};

const FinalScene: React.FC<{sceneFrame: number}> = ({sceneFrame}) => {
  return (
    <div
      style={{
        display: 'grid',
        gridTemplateColumns: '1fr 1fr',
        gap: 24,
        width: '100%',
      }}
    >
      <FeatureGrid
        sceneFrame={sceneFrame}
        items={[
          {
            title: '431 tests',
            text: 'Functional TYPO3 coverage keeps the integration honest.',
          },
          {
            title: 'Detailed docs',
            text: 'README, RST docs, technical overview, and architecture notes stay aligned.',
          },
          {
            title: 'Tool-focused design',
            text: 'LLMs get clean, schema-aware actions instead of brittle backend scraping.',
          },
          {
            title: 'Built for editors',
            text: 'Review and publishing still happen inside the normal TYPO3 workflow.',
          },
        ]}
      />
      <GlassCard minHeight={520}>
        <div
          style={{
            display: 'flex',
            flexDirection: 'column',
            justifyContent: 'space-between',
            height: '100%',
          }}
        >
          <div style={{display: 'flex', flexDirection: 'column', gap: 18}}>
            <div style={{fontSize: 34, fontWeight: 700}}>TYPO3 MCP Server</div>
            <div style={{fontSize: 24, lineHeight: 1.55, color: COLORS.muted}}>
              Safe AI-assisted editing for TYPO3, with workspace-first record
              writes, a dedicated file harness, and documentation that explains
              the real operational model.
            </div>
          </div>
          <div style={{display: 'flex', flexDirection: 'column', gap: 16}}>
            <div
              style={{
                padding: '18px 22px',
                borderRadius: 24,
                background: 'rgba(9, 26, 44, 0.74)',
                border: `1px solid ${COLORS.border}`,
                fontSize: 22,
              }}
            >
              Docs: README.md, Documentation/, TECHNICAL_OVERVIEW.md
            </div>
            <div
              style={{
                padding: '18px 22px',
                borderRadius: 24,
                background: COLORS.accentSoft,
                border: `1px solid ${COLORS.accent}`,
                fontSize: 24,
                fontWeight: 700,
              }}
            >
              Ready for MCP clients, TYPO3 editors, and project-specific
              extensions.
            </div>
          </div>
        </div>
      </GlassCard>
    </div>
  );
};

export const FEATURE_TOUR_DURATION = SCENE_DURATION * 6 + SCENE_GAP * 5;

export const MCPServerFeatureTour: React.FC = () => {
  const frame = useCurrentFrame();

  const scenes = [
    {
      title: 'Safe AI editing for TYPO3, built around workspaces',
      kicker: 'TYPO3 MCP Server',
      body: (
        <FeatureGrid
          sceneFrame={frame}
          items={[
            {
              title: 'Structured MCP access',
              text: 'Expose pages, records, schemas, search, workspaces, and file tools.',
            },
            {
              title: 'Editorial review stays in TYPO3',
              text: 'Record writes land in workspaces instead of touching live content directly.',
            },
            {
              title: 'Schema-aware clients',
              text: 'TCA and FlexForm inspection help LLMs understand valid fields before writing.',
            },
            {
              title: 'Controlled file workflows',
              text: 'The MCP file harness keeps uploads and generated assets inside a dedicated sandbox.',
            },
          ]}
        />
      ),
    },
    {
      title: 'A flow that maps cleanly to real TYPO3 work',
      kicker: 'How it works',
      body: <FlowDiagram sceneFrame={frame - SCENE_DURATION} />,
    },
    {
      title: 'Tools for navigation, schemas, content writing, and search',
      kicker: 'Tool surface',
      body: (
        <div style={{display: 'flex', flexDirection: 'column', gap: 22, width: '100%'}}>
          <ToolRow
            sceneFrame={frame - SCENE_DURATION * 2}
            title="Discovery"
            items={['GetPageTree', 'GetPage', 'ListTables', 'Search']}
          />
          <ToolRow
            sceneFrame={frame - SCENE_DURATION * 2 + 16}
            title="Schemas"
            items={['ReadTable', 'GetTableSchema', 'GetFlexFormSchema']}
          />
          <ToolRow
            sceneFrame={frame - SCENE_DURATION * 2 + 32}
            title="Write paths"
            items={['WriteTable', 'ListWorkspaces']}
          />
        </div>
      ),
    },
    {
      title: 'File access is sandboxed, configurable, and workspace-aware',
      kicker: 'File harness',
      body: <FileHarnessScene sceneFrame={frame - SCENE_DURATION * 3} />,
    },
    {
      title: 'Integrators get a backend module, editors keep visibility',
      kicker: 'Operations',
      body: <ModuleScene sceneFrame={frame - SCENE_DURATION * 4} />,
    },
    {
      title: 'Documented, tested, and ready for project-specific expansion',
      kicker: 'Quality',
      body: <FinalScene sceneFrame={frame - SCENE_DURATION * 5} />,
    },
  ];

  return (
    <AbsoluteFill>
      {scenes.map((scene, index) => {
        const from = index * (SCENE_DURATION + SCENE_GAP);
        return (
          <Sequence key={scene.title} from={from} durationInFrames={SCENE_DURATION}>
            <SceneShell
              title={scene.title}
              kicker={scene.kicker}
              body={scene.body}
              sceneFrame={frame - from}
            />
          </Sequence>
        );
      })}
    </AbsoluteFill>
  );
};
