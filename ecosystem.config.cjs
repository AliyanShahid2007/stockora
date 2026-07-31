module.exports = {
  apps: [
    {
      name: 'stockora',
      script: 'php',
      args: '-S 0.0.0.0:3000 -t /home/user/stockora',
      cwd: '/home/user/stockora',
      env: {
        NODE_ENV: 'development',
        PORT: 3000
      },
      watch: false,
      instances: 1,
      exec_mode: 'fork',
      autorestart: true,
      restart_delay: 1000
    }
  ]
}
